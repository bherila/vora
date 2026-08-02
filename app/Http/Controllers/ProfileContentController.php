<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use App\Services\Favorites\FavoriteService;
use App\Services\Media\MediaResponseService;
use App\Services\Privacy\ProfileGate;
use App\Services\Privacy\ViewAsContext;
use App\Services\Profile\ProfileContentQueries;
use App\Support\BlockGraph;
use App\Support\PaginationMeta;
use App\Support\PostPresenter;
use App\Support\StoryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Lists a profile's content for the profile-as-container view. A profile shows
 * the main user *or* one of their characters (the identity rail); each identity
 * has a media / stories / posts tab. Every listing is gated twice: the viewer
 * must be able to see the profile at all ({@see ProfileGate}), then each item is
 * filtered by its own audience via the shared `viewableBy` scope — so nothing
 * here ever exposes more than the item's own privacy allows. The queries
 * themselves live in {@see ProfileContentQueries} so counts and listings cannot
 * drift apart.
 */
class ProfileContentController extends Controller
{
    /** How many items the combined "Latest" strip returns. */
    private const RECENT_LIMIT = 8;

    public function __construct(
        private readonly ProfileGate $gate,
        private readonly MediaResponseService $responder,
        private readonly FavoriteService $favorites,
        private readonly ProfileContentQueries $queries,
        private readonly ViewAsContext $viewAs,
    ) {}

    public function media(Request $request, User $user): JsonResponse
    {
        $character = $this->resolveCharacter($request, $user);
        $viewer = $this->authorizeProfile($request, $user);

        $paginator = $this->queries->media($user, $viewer, $character)
            ->with(['character:id,display_name', 'interests'])
            ->latest()
            ->paginate((int) config('media.page_size', 24));

        $isOwnerOrAdmin = $viewer->id === $user->id || $viewer->isAdmin();

        return response()->json([
            'success' => true,
            ...($isOwnerOrAdmin
                ? $this->responder->page($paginator)
                : $this->responder->visitorPage($paginator)),
        ]);
    }

    public function posts(Request $request, User $user): JsonResponse
    {
        $character = $this->resolveCharacter($request, $user);
        $viewer = $this->authorizeProfile($request, $user);

        $paginator = $this->queries->posts($user, $viewer, $character)
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
                ))
                ->all(),
            'meta' => PaginationMeta::from($paginator),
        ]);
    }

    public function stories(Request $request, User $user): JsonResponse
    {
        $character = $this->resolveCharacter($request, $user);
        $viewer = $this->authorizeProfile($request, $user);

        $paginator = $this->queries->stories($user, $viewer, $character)
            ->with(['user', 'interests', 'authors.user', 'authors.character'])
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
        $character = $this->resolveCharacter($request, $user);
        $viewer = $this->authorizeProfile($request, $user);

        return response()->json([
            'success' => true,
            'data' => [
                'media' => $this->queries->media($user, $viewer, $character)->count(),
                'stories' => $this->queries->stories($user, $viewer, $character)->count(),
                'posts' => $this->queries->posts($user, $viewer, $character)->count(),
                'favorites' => $character instanceof Character ? 0 : count($this->favorites->visibleFor($user, $viewer)),
            ],
        ]);
    }

    /**
     * The combined "Latest" strip: the active identity's most recent media,
     * stories, and posts merged into one reverse-chronological list, in a single
     * request. Each branch reuses the tab's own gated builder, so the strip can
     * never show an item its tab would hide.
     */
    public function recent(Request $request, User $user): JsonResponse
    {
        $character = $this->resolveCharacter($request, $user);
        $viewer = $this->authorizeProfile($request, $user);

        $media = $this->queries->media($user, $viewer, $character)
            ->latest()->latest('id')->limit(self::RECENT_LIMIT)->get()
            ->map(function (Media $item) use ($user, $viewer): array {
                // Cross-user profile cards use the same opaque visitor asset
                // path as full media rows. Owner/admin cards retain their direct
                // signed-storage behavior for management.
                $isOwnerOrAdmin = $viewer->id === $user->id || $viewer->isAdmin();
                if ($isOwnerOrAdmin) {
                    $extras = $this->responder->extras($item, resolveHls: false);
                    $thumbnailUrl = $extras['thumbnail_url'] ?? $extras['url'];
                } else {
                    $thumbnailUrl = $this->responder->visitorAssetUrl($item);
                }

                return [
                    'type' => 'media',
                    'id' => $item->id,
                    'title' => $item->title,
                    'thumbnail_url' => $thumbnailUrl,
                    'href' => route('media.view', ['ulid' => $item->ulid], false),
                    'created_at' => $item->created_at?->toIso8601String(),
                ];
            });

        $stories = $this->queries->stories($user, $viewer, $character)
            ->latest()->latest('id')->limit(self::RECENT_LIMIT)->get()
            ->map(fn (Story $story): array => [
                'type' => 'story',
                'id' => $story->id,
                'title' => $story->title,
                'thumbnail_url' => null,
                'href' => route('stories.view', ['ulid' => $story->ulid], false),
                'created_at' => $story->created_at?->toIso8601String(),
            ]);

        $posts = $this->queries->posts($user, $viewer, $character)
            ->latest()->latest('id')->limit(self::RECENT_LIMIT)->get()
            ->map(fn (Post $post): array => [
                'type' => 'post',
                'id' => $post->id,
                'title' => Str::limit(trim($post->body), 120),
                'thumbnail_url' => null,
                'href' => route('posts.view', ['ulid' => $post->ulid], false),
                'created_at' => $post->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $media->concat($stories)->concat($posts)
                ->sortByDesc(fn (array $item): string => $item['created_at'] ?? '')
                ->take(self::RECENT_LIMIT)
                ->values()
                ->all(),
        ]);
    }

    /**
     * Ensure the viewer may see this profile at all; abort otherwise. Mirrors the
     * directory/profile rule: the owner always passes, others need an active,
     * approved target and an audience grant.
     */
    private function authorizeProfile(Request $request, User $user): User
    {
        $viewer = $this->viewAs->viewerFor($request, $user);
        $isSelf = $viewer instanceof User && $viewer->id === $user->id;
        $discoverable = $user->approved_at !== null && $user->isActive();

        if ($viewer instanceof User && ! BlockGraph::canViewIdentity($viewer, $user->id)) {
            abort(404, 'Not found.');
        }

        if (! $viewer instanceof User || (! $isSelf && (! $discoverable || ! $this->gate->canView($viewer, $user)))) {
            abort(403, 'Profile unavailable.');
        }

        return $viewer;
    }

    /**
     * Resolve an optional ?character_id to a character owned by the profile user,
     * or null for the main-user identity. A foreign/unknown id is a 404. A
     * Separate persona is also indistinguishable from an unknown id to anyone
     * except its owner or an admin; its content belongs exclusively under its
     * own /c/{ulid} surface.
     */
    private function resolveCharacter(Request $request, User $user): ?Character
    {
        $characterId = $request->query('character_id');
        if ($characterId === null || $characterId === '') {
            return null;
        }

        abort_unless(is_numeric($characterId), 404, 'Not found.');

        $viewer = $request->user();
        $mayResolveSeparate = ! $request->has('view_as') && $viewer instanceof User
            && ($viewer->id === $user->id || $viewer->isAdmin());

        $character = Character::query()
            ->where('id', (int) $characterId)
            ->where('user_id', $user->id)
            ->when(! $mayResolveSeparate, fn ($query) => $query->where('is_linked', true))
            ->first();
        abort_if($character === null, 404, 'Not found.');

        return $character;
    }
}
