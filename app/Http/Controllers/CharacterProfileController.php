<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\Favorite;
use App\Models\InterestRating;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use App\Services\Profile\ProfileContentQueries;
use App\Support\CharacterPresenter;
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
    ) {}

    public function page(Request $request, string $ulid): View
    {
        $character = $this->resolveCharacter($request, $ulid);

        return view('user.persona-profile', ['initialData' => [
            'personaProfile' => $this->payload($character, $request->user()),
        ]]);
    }

    public function media(Request $request, string $ulid): JsonResponse
    {
        $character = $this->resolveCharacter($request, $ulid);

        $paginator = $this->queries->media($character->user, $request->user(), $character)
            ->with(['character:id,display_name', 'interests'])
            ->latest()
            ->paginate((int) config('media.page_size', 24));

        return response()->json(['success' => true, ...$this->responder->page($paginator)]);
    }

    public function stories(Request $request, string $ulid): JsonResponse
    {
        $character = $this->resolveCharacter($request, $ulid);

        $paginator = $this->queries->stories($character->user, $request->user(), $character)
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
        $character = $this->resolveCharacter($request, $ulid);
        $viewer = $request->user();

        $paginator = $this->queries->posts($character->user, $viewer, $character)
            ->with(['user.profilePicture', 'character.profilePicture', 'attachments.attachable'])
            ->withEngagementCounts($viewer)
            ->latest()
            ->paginate((int) config('media.page_size', 24));

        return response()->json([
            'success' => true,
            'data' => collect($paginator->items())
                ->map(fn (Post $post): array => PostPresenter::view($post, $viewer, $this->responder))
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
        $character = $this->resolveCharacter($request, $ulid);
        $viewer = $request->user();

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
     */
    private function resolveCharacter(Request $request, string $ulid): Character
    {
        $character = Character::query()
            ->where('ulid', $ulid)
            ->with(['user', 'profilePicture'])
            ->first();
        if ($character === null) {
            abort(404, 'Not found.');
        }
        $this->authorizeOr404('view', $character);

        return $character;
    }

    /**
     * The persona page header payload. For a Linked persona the owner appears
     * as page meta (name + profile link) — that is the choice Linked makes.
     * For a Separate persona the payload carries nothing that resolves to the
     * human: no owner block, and inherited interests are withheld because the
     * owner's interest fingerprint is a correlation vector.
     *
     * @return array<string, mixed>
     */
    private function payload(Character $character, User $viewer): array
    {
        $owner = $character->user;
        $isOwner = $viewer->id === $character->user_id;

        return CharacterPresenter::publicCard($character, $this->responder, $viewer) + [
            'is_owner' => $isOwner,
            'is_linked' => $character->is_linked,
            'owner' => $character->is_linked ? [
                'display_name' => $owner->display_name ?: $owner->name,
                // The owner's own profile page 404s on self; send them to /me.
                'href' => $isOwner ? route('me', [], false) : route('users.profile', $owner, false),
            ] : null,
            'interests' => $this->interests($character),
            'viewer_favorited' => ! $isOwner && Favorite::query()
                ->where('user_id', $viewer->id)
                ->where('favoritable_type', $character->getMorphClass())
                ->where('favoritable_id', $character->id)
                ->exists(),
            'can_report' => ! $isOwner,
        ];
    }

    /**
     * The interests shown on the persona header. A persona with its own
     * ratings shows those; one inheriting from the owner shows the owner's
     * profile interests only when Linked — a Separate persona showing the
     * owner's exact interest signature would be a correlation vector, so it
     * shows none.
     *
     * @return list<array{id: int, name: string|null}>
     */
    private function interests(Character $character): array
    {
        if ($character->inherit_interests && ! $character->is_linked) {
            return [];
        }

        $ratings = InterestRating::query()
            ->with('interest:id,name')
            ->where('user_id', $character->user_id)
            ->where('level', '>', 0)
            ->when(
                $character->inherit_interests,
                fn ($query) => $query->whereNull('character_id'),
                fn ($query) => $query->where('character_id', $character->id),
            )
            ->get();

        return $ratings
            ->map(fn (InterestRating $rating): array => ['id' => (int) $rating->interest_id, 'name' => $rating->interest?->name])
            ->values()
            ->all();
    }
}
