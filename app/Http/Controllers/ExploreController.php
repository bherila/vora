<?php

namespace App\Http\Controllers;

use App\Enums\MediaPurpose;
use App\Enums\ModerationStatus;
use App\Enums\StoryStatus;
use App\Http\Requests\Media\ListMediaRequest;
use App\Models\Character;
use App\Models\InterestRating;
use App\Models\Media;
use App\Models\Story;
use App\Models\User;
use App\Services\Favorites\FavoriteService;
use App\Services\Media\MediaResponseService;
use App\Support\CharacterPresenter;
use App\Support\MediaFilter;
use App\Support\MuteGraph;
use App\Support\PaginationMeta;
use App\Support\StoryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Cross-user media exploration. This is the privacy-aware counterpart to the
 * owner's library: it lists media uploaded by *anyone*, but only rows the viewer
 * is allowed to discover.
 *
 * The discovery filters (type, interests) and the response shape are shared with
 * {@see MediaController} via {@see MediaFilter} and {@see MediaResponseService};
 * the only thing that differs here is the base scoping — `discoverable`
 * (strictly any-user visibility, with no owner or admin exception) intersected
 * with approved moderation. Keeping that intersection in one query is what makes
 * the privacy rule auditable as search/exploration grows.
 */
class ExploreController extends Controller
{
    public function __construct(
        private readonly MediaResponseService $responder,
        private readonly FavoriteService $favorites,
    ) {}

    /**
     * The exploration page (results are fetched client-side from `apiIndex`).
     */
    public function page(ListMediaRequest $request): View
    {
        // Explore opens pre-filtered to the viewer's own interests. We seed the
        // request so the server-rendered first page matches the filter the client
        // shows; the user can Reset/Clear to deviate (handled client-side, and the
        // refresh endpoint honours whatever interest_ids the client then sends).
        $defaultInterestIds = $this->defaultInterestIds($request->user());
        if ($defaultInterestIds !== [] && (array) $request->input('interest_ids', []) === []) {
            $request->merge(['interest_ids' => $defaultInterestIds]);
        }

        return view('user.explore', ['initialData' => [
            'explore' => [
                'media' => $this->mediaPayload($request),
                'stories' => $this->storiesPayload($request),
                'personas' => $this->personasPayload($request),
                'default_interest_ids' => $defaultInterestIds,
            ],
        ]]);
    }

    /**
     * The viewer's own rated interests (level > 0), used as the default Explore
     * filter. Empty when the viewer has rated nothing — Explore then shows
     * everything rather than a blank page.
     *
     * @return list<int>
     */
    private function defaultInterestIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return InterestRating::query()
            ->where('user_id', $user->id)
            ->whereNull('character_id')
            ->where('level', '>', 0)
            ->pluck('interest_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * List media discoverable by the current viewer, newest first, paginated.
     */
    public function apiIndex(ListMediaRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            ...$this->mediaPayload($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaPayload(ListMediaRequest $request): array
    {
        $query = Media::query()
            ->where('purpose', MediaPurpose::Gallery->value)
            ->discoverable()
            ->moderationStatus(ModerationStatus::Approved)
            ->where('upload_status', 'ready')
            // Hide media owned by deactivated, disabled, or deleted accounts.
            // whereHas respects the User soft-delete scope, so trashed owners
            // drop out too; active() covers deactivated + disabled.
            ->whereHas('user', fn ($q) => $q->active())
            ->with('interests')
            ->latest();

        if ($request->user() instanceof User) {
            MuteGraph::excludeMutedIdentities(
                $query,
                $request->user()->id,
                'media.user_id',
                'media.character_id',
            );
        }

        $paginator = MediaFilter::fromRequest($request)
            ->applyTo($query)
            ->paginate((int) config('media.page_size', 24));

        return $this->favorites->annotateListing(
            $this->responder->visitorPage($paginator),
            $request->user(),
            'media',
        );
    }

    /**
     * List published, approved stories discoverable by the current viewer.
     */
    public function apiStories(ListMediaRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            ...$this->storiesPayload($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function storiesPayload(ListMediaRequest $request): array
    {
        $interestIds = array_values(array_unique(array_map(
            'intval',
            (array) $request->input('interest_ids', []),
        )));

        $query = Story::query()
            ->where('status', StoryStatus::Published->value)
            ->discoverable()
            ->moderationStatus(ModerationStatus::Approved)
            ->whereHas('user', fn ($q) => $q->active())
            ->with(['user', 'interests', 'authors.user', 'authors.character'])
            ->withCount('nodes')
            ->withAnyInterest($interestIds)
            ->latest('published_at')
            ->latest('id');

        if ($request->user() instanceof User) {
            MuteGraph::excludeMutedStoryAuthors($query, $request->user()->id);
        }

        $paginator = $query->paginate((int) config('media.page_size', 24));

        return $this->favorites->annotateListing([
            'data' => collect($paginator->items())
                ->map(fn (Story $story): array => StoryPresenter::discoverableView($story))
                ->values()
                ->all(),
            'meta' => PaginationMeta::from($paginator),
        ], $request->user(), 'story');
    }

    /**
     * List personas discoverable by the current viewer.
     */
    public function apiPersonas(ListMediaRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            ...$this->personasPayload($request),
        ]);
    }

    /**
     * Discoverable personas: strictly the Everyone audience, opted into
     * discovery, with an active + approved owner — `discoverable = false`
     * means the persona page is direct-link-only and never listed here. The
     * interest filter matches only a persona's *own* ratings: an inheriting
     * persona has no rows of its own, which is deliberate — matching through
     * inheritance would surface the owner's interest fingerprint on a
     * pseudonymous card.
     *
     * @return array<string, mixed>
     */
    private function personasPayload(ListMediaRequest $request): array
    {
        $interestIds = array_values(array_unique(array_map(
            'intval',
            (array) $request->input('interest_ids', []),
        )));

        $query = Character::query()
            ->discoverable()
            ->whereHas('user', fn ($q) => $q->active()->whereNotNull('approved_at'))
            ->with('profilePicture')
            ->when($interestIds !== [], fn ($q) => $q->whereHas(
                'interestRatings',
                fn ($ratings) => $ratings->where('level', '>', 0)->whereIn('interest_id', $interestIds),
            ))
            ->latest()
            ->latest('id');

        if ($request->user() instanceof User) {
            MuteGraph::excludeMutedIdentities(
                $query,
                $request->user()->id,
                'characters.user_id',
                'characters.id',
            );
        }

        $paginator = $query->paginate((int) config('media.page_size', 24));
        $viewer = $request->user();

        return $this->favorites->annotateListing([
            'data' => collect($paginator->items())
                ->map(fn (Character $character): array => CharacterPresenter::publicCard($character, $this->responder, $viewer))
                ->values()
                ->all(),
            'meta' => PaginationMeta::from($paginator),
        ], $viewer, 'character');
    }
}
