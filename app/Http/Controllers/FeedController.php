<?php

namespace App\Http\Controllers;

use App\Enums\ModerationStatus;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use App\Support\FollowGraph;
use App\Support\PostPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The viewer's reverse-chronological timeline: their own posts plus posts from
 * the accounts they follow, gated by each post's audience and (for other
 * people's posts) admin review. Keyset-paginated so the feed can grow without
 * the offset drift of page numbers.
 */
class FeedController extends Controller
{
    public function __construct(private readonly MediaResponseService $mediaResponder) {}

    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $viewerId = $viewer?->id;

        $posts = Post::query()
            // Membership: the viewer's own posts plus posts from accounts they
            // follow, expressed as a correlated subquery so the query stays
            // page-sized regardless of how many accounts the viewer follows.
            ->where(function (Builder $query) use ($viewerId): void {
                $query->where('posts.user_id', $viewerId)
                    ->orWhereExists(function (QueryBuilder $sub) use ($viewerId): void {
                        FollowGraph::constrainViewerFollowsOwner($sub, 'posts.user_id', (int) $viewerId);
                    });
            })
            // The viewer always sees their own posts; everyone else's must have
            // passed review.
            ->where(function (Builder $query) use ($viewerId): void {
                $query->where('posts.user_id', $viewerId)
                    ->orWhere('posts.moderation_status', ModerationStatus::Approved->value);
            })
            // Hide posts from accounts that have since deactivated, been
            // disabled, or deleted — the feed must not become a bypass for an
            // owner the per-record policies would now reject.
            ->whereHas('user', fn (Builder $query) => $query->active())
            ->viewableBy($viewer)
            ->with(['user', 'character.profilePicture', 'attachments.attachable'])
            ->withEngagementCounts($viewer)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) config('media.page_size', 24));

        return response()->json([
            'success' => true,
            'data' => collect($posts->items())
                ->map(fn (Post $post): array => PostPresenter::view($post, $viewer instanceof User ? $viewer : null, $this->mediaResponder))
                ->values(),
            'next_cursor' => $posts->nextCursor()?->encode(),
        ]);
    }
}
