<?php

namespace App\Http\Controllers;

use App\Enums\ModerationStatus;
use App\Models\FollowRequest;
use App\Models\Post;
use App\Models\User;
use App\Support\PostPresenter;
use Illuminate\Database\Eloquent\Builder;
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
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();

        // Accounts the viewer follows (accepted follow), plus themselves.
        $authorIds = FollowRequest::query()
            ->where('requester_id', $viewer?->id)
            ->where('status', FollowRequest::STATUS_ACCEPTED)
            ->pluck('recipient_id')
            ->push($viewer?->id)
            ->all();

        $posts = Post::query()
            ->whereIn('user_id', $authorIds)
            // The viewer always sees their own posts; everyone else's must have
            // passed review. viewableBy() then applies the audience tier.
            ->where(function (Builder $query) use ($viewer): void {
                $query->where('user_id', $viewer?->id)
                    ->orWhere('moderation_status', ModerationStatus::Approved->value);
            })
            ->viewableBy($viewer)
            ->with(['user', 'attachments.attachable'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) config('media.page_size', 24));

        return response()->json([
            'success' => true,
            'data' => collect($posts->items())
                ->map(fn (Post $post): array => PostPresenter::view($post, $viewer instanceof User ? $viewer : null))
                ->values(),
            'next_cursor' => $posts->nextCursor()?->encode(),
        ]);
    }
}
