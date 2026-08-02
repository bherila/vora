<?php

namespace App\Http\Controllers;

use App\Models\FollowRequest;
use App\Models\Interest;
use App\Models\Post;
use App\Services\Media\MediaResponseService;
use App\Services\Post\FeedQueryService;
use App\Support\Onboarding;
use App\Support\PostPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The viewer's reverse-chronological timeline: their own posts plus posts from
 * the accounts they follow, gated by each post's audience and (for other
 * people's posts) admin review. Keyset-paginated so the feed can grow without
 * the offset drift of page numbers.
 */
class FeedController extends Controller
{
    public function __construct(
        private readonly MediaResponseService $mediaResponder,
        private readonly FeedQueryService $feeds,
    ) {}

    public function page(Request $request): View
    {
        $interest = $request->query('interest');
        if ($interest !== null && (! is_string($interest) || ! Interest::query()->where('slug', $interest)->exists())) {
            abort(404, 'Not found.');
        }

        return view('feed', [
            'initialData' => [
                'feedOnboarding' => Onboarding::payload($request->user()),
                'feedHasFollowing' => FollowRequest::query()
                    ->where('requester_id', $request->user()->id)
                    ->where('status', FollowRequest::STATUS_ACCEPTED)
                    ->exists(),
                'feedInterests' => Interest::query()
                    ->orderBy('name')
                    ->get(['name', 'slug'])
                    ->map(fn (Interest $item): array => [
                        'name' => $item->name,
                        'slug' => $item->slug,
                    ]),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['success' => true, ...$this->payload($request)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $viewer = $request->user();
        // Following is the settled safe default. Mixed must remain an explicit
        // opt-in, and unknown values must not silently widen membership.
        $scope = $request->query('scope') === 'mixed' ? 'mixed' : 'following';
        $interest = $request->query('interest');
        $context = is_string($interest) ? Interest::query()->where('slug', $interest)->first() : null;
        if ($interest !== null && $context === null) {
            abort(404, 'Not found.');
        }

        $posts = $this->feeds->build($viewer, $scope, $context)
            ->withEngagementCounts($viewer)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) config('media.page_size', 24));

        return [
            'data' => collect($posts->items())
                ->map(fn (Post $post): array => PostPresenter::view($post, $viewer, $this->mediaResponder))
                ->values(),
            'next_cursor' => $posts->nextCursor()?->encode(),
        ];
    }
}
