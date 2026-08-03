<?php

namespace App\Http\Controllers;

use App\Http\Requests\Activity\IndexActivityRequest;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function page(): View
    {
        return view('user.activity');
    }

    public function index(IndexActivityRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $type = (string) ($request->validated('type') ?? 'posts');

        if ($type === 'posts') {
            // is_feed_hidden marks the discussion posts this codebase creates on
            // the user's behalf for their own media. They are not something the
            // user wrote, so they do not belong in a listing of their writing.
            $page = $this->paginate(
                Post::query()->where('user_id', $user->id)->where('is_feed_hidden', false)
            );
            $items = collect($page->items())->map(fn (Post $post): array => [
                'ulid' => $post->ulid,
                'type' => 'post',
                'body' => $post->body,
                'status' => $post->isRejected() ? 'rejected' : 'active',
                'created_at' => $post->created_at?->toIso8601String(),
                'parent' => ['ulid' => $post->ulid],
            ]);
        } else {
            $page = $this->paginate(
                PostComment::query()
                    ->where('user_id', $user->id)
                    ->when($type === 'comments', fn (Builder $query): Builder => $query->whereNull('parent_id'))
                    ->when($type === 'replies', fn (Builder $query): Builder => $query->whereNotNull('parent_id'))
                    ->with('post')
            );
            $items = collect($page->items())->map(function (PostComment $comment) use ($user, $type): array {
                $post = $comment->post;
                $parentViewable = $post instanceof Post && Gate::forUser($user)->allows('view', $post);

                return [
                    'ulid' => $comment->ulid,
                    'type' => $type === 'replies' ? 'reply' : 'comment',
                    'body' => $comment->body,
                    // Admin rejection is final presentation state and must not
                    // be masked by an earlier owner-removal marker.
                    'status' => $comment->isRejected() ? 'rejected' : ($comment->removed_at !== null ? 'removed_by_post_owner' : 'active'),
                    'created_at' => $comment->created_at?->toIso8601String(),
                    'parent' => $parentViewable ? ['ulid' => $post->ulid] : null,
                    'parent_unavailable' => ! $parentViewable,
                ];
            });
        }

        return response()->json([
            'success' => true,
            'data' => $items->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /**
     * Keyset pagination, matching the feed. Authorship listings grow without
     * bound, and an unpaginated get() eventually times out the one surface a
     * user needs most when they want to clean up after themselves.
     *
     * @template TModel of Post|PostComment
     *
     * @param  Builder<TModel>  $query
     * @return CursorPaginator<int, TModel>
     */
    private function paginate(Builder $query): CursorPaginator
    {
        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) config('media.page_size', 24));
    }

    public function destroyComment(Request $request, string $ulid): JsonResponse
    {
        $comment = PostComment::query()
            ->where('ulid', $ulid)
            ->where('user_id', $request->user()?->id)
            ->first();
        if (! $comment instanceof PostComment) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }
        $comment->delete();

        return response()->json(['success' => true, 'message' => 'Contribution deleted.']);
    }
}
