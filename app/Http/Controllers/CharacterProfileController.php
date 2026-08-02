<?php

namespace App\Http\Controllers;

use App\Enums\RestrictionCapability;
use App\Models\Character;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use App\Services\Moderation\RestrictionGate;
use App\Services\Privacy\ViewAsContext;
use App\Services\Profile\PersonaProfilePayload;
use App\Services\Profile\ProfileContentQueries;
use App\Services\Profile\RecentProfileTrail;
use App\Support\PaginationMeta;
use App\Support\PostPresenter;
use App\Support\StoryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A persona's public profile (/c/{ulid}) and its content endpoints.
 *
 * Access is gated on the character's OWN audience (via CharacterPolicy →
 * Character::isViewableBy(), the single visibility rule), never on the owner's
 * profile audience — a viewer who may not see the human's profile can still see
 * a persona whose audience admits them. That independence is what makes a
 * Separate persona a real pseudonym rather than a styled section of the
 * owner's page. Each tab's items are additionally filtered by their own
 * per-item audience through {@see ProfileContentQueries}.
 *
 * `discoverable = false` means direct-link-only, mirroring the media
 * convention: the page stays reachable at /c/{ulid} for anyone the persona's
 * own audience admits, but the persona is never listed on discovery surfaces
 * (Explore's Personas tab, the People directory).
 */
class CharacterProfileController extends Controller
{
    public function __construct(
        private readonly MediaResponseService $responder,
        private readonly ProfileContentQueries $queries,
        private readonly PersonaProfilePayload $profilePayload,
        private readonly ViewAsContext $viewAs,
        private readonly RecentProfileTrail $recentProfiles,
        private readonly RestrictionGate $restrictions,
    ) {}

    public function page(Request $request, string $ulid): View
    {
        [$character, $viewer] = $this->resolveCharacter($request, $ulid);
        $profile = $this->profilePayload->build(
            $character,
            $viewer,
            allowMutations: $this->viewAs->mode() === null,
        );
        if ($this->viewAs->mode() === null) {
            $this->recentProfiles->recordCharacter($viewer, $character);
        }

        return view('user.persona-profile', ['initialData' => [
            'personaProfile' => $profile,
            'profileViewAs' => $this->viewAs->payload(),
        ]]);
    }

    public function media(Request $request, string $ulid): JsonResponse
    {
        [$character, $viewer] = $this->resolveCharacter($request, $ulid);

        $paginator = $this->queries->media($character->user, $viewer, $character)
            ->with(['character:id,display_name', 'interests'])
            ->latest()
            ->paginate((int) config('media.page_size', 24));

        return response()->json(['success' => true, ...$this->responder->visitorPage($paginator)]);
    }

    public function stories(Request $request, string $ulid): JsonResponse
    {
        [$character, $viewer] = $this->resolveCharacter($request, $ulid);

        $paginator = $this->queries->stories($character->user, $viewer, $character)
            ->with(['user', 'interests', 'authors.user', 'authors.character'])
            ->withCount('nodes')
            ->latest('id')
            ->paginate((int) config('media.page_size', 24));

        return response()->json([
            'success' => true,
            'data' => collect($paginator->items())
                ->map(fn (Story $story): array => StoryPresenter::personaView($story, $character))
                ->all(),
            'meta' => PaginationMeta::from($paginator),
        ]);
    }

    public function posts(Request $request, string $ulid): JsonResponse
    {
        [$character, $viewer] = $this->resolveCharacter($request, $ulid);

        $paginator = $this->queries->posts($character->user, $viewer, $character)
            ->with(['user.profilePicture', 'character.profilePicture', 'contextInterest', 'attachments.attachable'])
            ->withEngagementCounts($viewer)
            ->latest()
            ->paginate((int) config('media.page_size', 24));

        return response()->json([
            'success' => true,
            'data' => collect($paginator->items())
                ->map(fn (Post $post): array => PostPresenter::view(
                    $post,
                    $viewer,
                    $this->responder,
                    allowMutations: $this->viewAs->mode() === null,
                    canViewNonOwnedMedia: ! $this->restrictions->denies($viewer, RestrictionCapability::MediaView),
                ))
                ->all(),
            'meta' => PaginationMeta::from($paginator),
        ]);
    }

    /**
     * Per-tab totals for the persona page's tab badges. Runs the exact listing
     * builders, so a badge never implies content its tab would hide.
     */
    public function counts(Request $request, string $ulid): JsonResponse
    {
        [$character, $viewer] = $this->resolveCharacter($request, $ulid);

        return response()->json([
            'success' => true,
            'data' => [
                'media' => $this->queries->media($character->user, $viewer, $character)->count(),
                'stories' => $this->queries->stories($character->user, $viewer, $character)->count(),
                'posts' => $this->queries->posts($character->user, $viewer, $character)->count(),
            ],
        ]);
    }

    /**
     * Resolve a persona by ulid, answering the exact same generic 404 for
     * "doesn't exist" and "exists but you may not see it" — the Media pattern.
     * firstOrFail() + Gate::authorize would leak existence via 403-vs-404, and
     * for a Separate persona "this ULID exists but is hidden from you" is a
     * deanonymization oracle. Soft-deleted personas fall out via the default
     * SoftDeletes scope; an inactive or unapproved owner 404s inside
     * Character::isViewableBy().
     *
     * @return array{0: Character, 1: User}
     */
    private function resolveCharacter(Request $request, string $ulid): array
    {
        $character = Character::query()
            ->where('ulid', $ulid)
            ->with(['user', 'profilePicture'])
            ->first();
        if ($character === null) {
            abort(404, 'Not found.');
        }
        $viewer = $this->viewAs->viewerFor($request, $character->user, $character);
        if (! $character->isViewableBy($viewer)) {
            abort(404, 'Not found.');
        }

        return [$character, $viewer];
    }
}
