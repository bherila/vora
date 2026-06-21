<?php

namespace App\Http\Controllers;

use App\Enums\ModerationStatus;
use App\Models\FollowRequest;
use App\Models\InterestRating;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use App\Support\FollowGraph;
use App\Support\PostPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
    public function __construct(private readonly MediaResponseService $mediaResponder) {}

    public function page(Request $request): View
    {
        $viewer = $request->user();

        return view('feed', ['initialData' => [
            'feed' => $this->payload($request),
            'onboarding' => $viewer instanceof User ? $this->onboarding($viewer) : null,
        ]]);
    }

    /**
     * First-run checklist state for the feed header. Returns null once every step
     * is complete so the checklist disappears for established users. Each flag is
     * a single existence check, kept cheap because the feed page already does the
     * heavier timeline query.
     *
     * @return array<string, bool>|null
     */
    private function onboarding(User $viewer): ?array
    {
        $steps = [
            'has_avatar' => $viewer->profile_picture_media_id !== null,
            'has_interests' => InterestRating::query()
                ->where('user_id', $viewer->id)
                ->whereNull('character_id')
                ->where('level', '>', 0)
                ->exists(),
            'is_following' => FollowRequest::query()
                ->where('requester_id', $viewer->id)
                ->where('status', 'accepted')
                ->exists(),
            'has_posted' => Post::query()->where('user_id', $viewer->id)->exists(),
        ];

        return in_array(false, $steps, true) ? $steps : null;
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
            ->with(['user.profilePicture', 'character.profilePicture', 'attachments.attachable'])
            ->withEngagementCounts($viewer)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) config('media.page_size', 24));

        return [
            'data' => collect($posts->items())
                ->map(fn (Post $post): array => PostPresenter::view($post, $viewer instanceof User ? $viewer : null, $this->mediaResponder))
                ->values(),
            'next_cursor' => $posts->nextCursor()?->encode(),
        ];
    }
}
