<?php

namespace App\Http\Controllers;

use App\Enums\MediaPurpose;
use App\Enums\ModerationStatus;
use App\Enums\StoryStatus;
use App\Models\Character;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use App\Services\Favorites\FavoriteService;
use App\Services\Media\MediaResponseService;
use App\Services\Privacy\ProfileGate;
use App\Support\PaginationMeta;
use App\Support\PostPresenter;
use App\Support\StoryPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lists a profile's content for the profile-as-container view. A profile shows
 * the main user *or* one of their characters (the strip); each identity has a
 * media / stories / posts tab. Every listing is gated twice: the viewer must be
 * able to see the profile at all ({@see ProfileGate}), then each item is filtered
 * by its own audience via the shared `viewableBy` scope — so nothing here ever
 * exposes more than the item's own privacy allows.
 */
class ProfileContentController extends Controller
{
    public function __construct(
        private readonly ProfileGate $gate,
        private readonly MediaResponseService $responder,
        private readonly FavoriteService $favorites,
    ) {}

    public function media(Request $request, User $user): JsonResponse
    {
        $viewer = $this->authorizeProfile($request, $user);
        $character = $this->resolveCharacter($request, $user);

        $paginator = $this->mediaQuery($user, $viewer, $character)
            ->with(['character:id,display_name', 'interests'])
            ->latest()
            ->paginate((int) config('media.page_size', 24));

        return response()->json(['success' => true, ...$this->responder->page($paginator)]);
    }

    public function posts(Request $request, User $user): JsonResponse
    {
        $viewer = $this->authorizeProfile($request, $user);
        $character = $this->resolveCharacter($request, $user);

        $paginator = $this->postsQuery($user, $viewer, $character)
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

    public function stories(Request $request, User $user): JsonResponse
    {
        $viewer = $this->authorizeProfile($request, $user);
        $character = $this->resolveCharacter($request, $user);

        $paginator = $this->storiesQuery($user, $viewer, $character)
            ->with(['user', 'interests', 'authors.user'])
            ->withCount('nodes')
            ->latest('id')
            ->paginate((int) config('media.page_size', 24));

        return response()->json([
            'success' => true,
            'data' => collect($paginator->items())
                ->map(fn (Story $story): array => StoryPresenter::discoverableView($story))
                ->all(),
            'meta' => PaginationMeta::from($paginator),
        ]);
    }

    /**
     * Per-identity content counts for the profile tab badges. Each count uses the
     * exact same gating as its listing, so a badge never implies content the
     * viewer could not actually open. Favorites belong to the main user, so the
     * favorites count is zero when a character identity is selected.
     */
    public function counts(Request $request, User $user): JsonResponse
    {
        $viewer = $this->authorizeProfile($request, $user);
        $character = $this->resolveCharacter($request, $user);

        return response()->json([
            'success' => true,
            'data' => [
                'media' => $this->mediaQuery($user, $viewer, $character)->count(),
                'stories' => $this->storiesQuery($user, $viewer, $character)->count(),
                'posts' => $this->postsQuery($user, $viewer, $character)->count(),
                'favorites' => $character instanceof Character ? 0 : count($this->favorites->visibleFor($user, $viewer)),
            ],
        ]);
    }

    /**
     * @return Builder<Media>
     */
    private function mediaQuery(User $user, User $viewer, ?Character $character): Builder
    {
        $query = Media::query()
            ->where('user_id', $user->id)
            ->where('purpose', MediaPurpose::Gallery->value)
            ->where('upload_status', 'ready')
            ->viewableBy($viewer);

        $this->scopeToIdentity($query, $character);
        $this->hideUnapprovedFromOthers($query, $viewer, $user);

        return $query;
    }

    /**
     * @return Builder<Post>
     */
    private function postsQuery(User $user, User $viewer, ?Character $character): Builder
    {
        $query = Post::query()
            ->where('user_id', $user->id)
            ->viewableBy($viewer);

        $this->scopeToIdentity($query, $character);
        $this->hideUnapprovedFromOthers($query, $viewer, $user);

        return $query;
    }

    /**
     * @return Builder<Story>
     */
    private function storiesQuery(User $user, User $viewer, ?Character $character): Builder
    {
        $query = Story::query()->viewableBy($viewer);

        if ($character instanceof Character) {
            // Character tab: stories that involve this character (the polymorphic
            // "involves" tag). The morph alias is 'character' (see morph map).
            $query->whereHas('involvements', fn (Builder $q) => $q
                ->where('involvable_type', 'character')
                ->where('involvable_id', $character->id));
        } else {
            $query->where('user_id', $user->id);
        }

        // Non-owners only ever see another profile's published, approved stories.
        if ($viewer->id !== $user->id && ! $viewer->isAdmin()) {
            $query->where('status', StoryStatus::Published->value)
                ->where('moderation_status', ModerationStatus::Approved->value);
        }

        return $query;
    }

    /**
     * Ensure the viewer may see this profile at all; abort otherwise. Mirrors the
     * directory/profile rule: the owner always passes, others need an active,
     * approved target and an audience grant.
     */
    private function authorizeProfile(Request $request, User $user): User
    {
        $viewer = $request->user();
        $isSelf = $viewer instanceof User && $viewer->id === $user->id;
        $discoverable = $user->approved_at !== null && $user->isActive();

        if (! $viewer instanceof User || (! $isSelf && (! $discoverable || ! $this->gate->canView($viewer, $user)))) {
            abort(403, 'Profile unavailable.');
        }

        return $viewer;
    }

    /**
     * Resolve an optional ?character_id to a character owned by the profile user,
     * or null for the main-user identity. A foreign/unknown id is a 404.
     */
    private function resolveCharacter(Request $request, User $user): ?Character
    {
        $characterId = $request->query('character_id');
        if ($characterId === null || $characterId === '') {
            return null;
        }

        abort_unless(is_numeric($characterId), 404, 'Not found.');

        $character = Character::query()->where('id', (int) $characterId)->where('user_id', $user->id)->first();
        abort_if($character === null, 404, 'Not found.');

        return $character;
    }

    /**
     * Media/posts carry a character_id: scope to that character, or to the main
     * user's own (character-less) content.
     *
     * @param  Builder<Media>|Builder<Post>  $query
     */
    private function scopeToIdentity(Builder $query, ?Character $character): void
    {
        if ($character instanceof Character) {
            $query->where('character_id', $character->id);
        } else {
            $query->whereNull('character_id');
        }
    }

    /**
     * @param  Builder<Media>|Builder<Post>  $query
     */
    private function hideUnapprovedFromOthers(Builder $query, User $viewer, User $owner): void
    {
        if ($viewer->id !== $owner->id && ! $viewer->isAdmin()) {
            $query->where('moderation_status', ModerationStatus::Approved->value);
        }
    }
}
