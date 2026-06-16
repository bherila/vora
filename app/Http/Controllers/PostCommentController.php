<?php

namespace App\Http\Controllers;

use App\Enums\ModerationStatus;
use App\Http\Requests\Post\CommentRequest;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use App\Support\PostCommentPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Comments on a post. Reading and writing are gated by the post's view policy.
 * Comments publish immediately and are moderated reactively (consistent with
 * posts); non-authors only ever see approved comments.
 */
class PostCommentController extends Controller
{
    public function index(Request $request, Post $post): JsonResponse
    {
        Gate::authorize('view', $post);

        $viewer = $request->user();

        $comments = $post->comments()
            ->with('user:id,name,display_name')
            ->where(function (Builder $query) use ($viewer): void {
                // Others see only approved comments; the author always sees theirs.
                $query->where('moderation_status', ModerationStatus::Approved->value)
                    ->orWhere('user_id', $viewer?->id);
            })
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $comments->map(fn (PostComment $comment): array => PostCommentPresenter::view($comment))->values(),
        ]);
    }

    public function store(CommentRequest $request, Post $post): JsonResponse
    {
        Gate::authorize('view', $post);

        /** @var User $user */
        $user = $request->user();

        $comment = $post->comments()->make([
            'user_id' => $user->id,
            'body' => $request->validated('body'),
            'parent_id' => $this->resolveParentId($request, $post),
        ]);
        // Auto-approve: short replies publish immediately and are moderated
        // reactively. The moderation column is not mass-assignable.
        $comment->moderation_status = ModerationStatus::Approved;
        $comment->save();

        $comment->load('user:id,name,display_name');

        return response()->json([
            'success' => true,
            'data' => PostCommentPresenter::view($comment),
        ], 201);
    }

    public function destroy(Request $request, Post $post, PostComment $comment): JsonResponse
    {
        if ($comment->post_id !== $post->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        Gate::authorize('delete', $comment);

        $comment->delete();

        return response()->json(['success' => true, 'message' => 'Comment deleted.']);
    }

    /**
     * Validate an optional parent: it must be a top-level comment on the same
     * post (one level of threading).
     */
    private function resolveParentId(CommentRequest $request, Post $post): ?int
    {
        $parentId = $request->validated('parent_id');
        if ($parentId === null) {
            return null;
        }

        $parent = PostComment::query()->find($parentId);
        if ($parent === null || $parent->post_id !== $post->id || $parent->parent_id !== null) {
            throw ValidationException::withMessages(['parent_id' => 'You can only reply to a comment on this post.']);
        }

        return (int) $parentId;
    }
}
