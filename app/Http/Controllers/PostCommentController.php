<?php

namespace App\Http\Controllers;

use App\Enums\ModerationStatus;
use App\Http\Requests\Post\CommentRequest;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use App\Notifications\PostCommentedOn;
use App\Services\Media\MediaResponseService;
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
    public function __construct(private readonly MediaResponseService $mediaResponder) {}

    public function index(Request $request, Post $post): JsonResponse
    {
        // Unlike click-driven reads, a polled thread explicitly answers 403 when
        // access is lost so the client can clear stale state and stop polling.
        abort_unless($request->user()?->can('view', $post), 403, 'Forbidden.');

        $viewer = $request->user();
        $token = hash_hmac(
            'sha256',
            $post->id.':'.$post->comment_revision.':'.$viewer->id,
            (string) config('app.key'),
        );
        $etag = '"'.$token.'"';
        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'private, no-cache',
        ];

        if ($request->header('If-None-Match') === $etag) {
            return response()->json(status: 304)->withHeaders($headers);
        }

        $post->loadMissing('character.profilePicture');

        $comments = $post->comments()
            ->withTrashed()
            ->with(['user:id,name,display_name,profile_picture_media_id', 'user.profilePicture'])
            ->where(function (Builder $query) use ($viewer): void {
                $query->threadVisibleTo($viewer)
                    ->orWhere(function (Builder $tombstone) use ($viewer): void {
                        $tombstone
                            // Owner removal is transparent even for a leaf or a
                            // reply, provided its thread position is reachable.
                            ->where(function (Builder $removed) use ($viewer): void {
                                $removed->tombstoneVisibleTo($viewer)
                                    ->whereNotNull('removed_at')
                                    ->where(function (Builder $position) use ($viewer): void {
                                        $position->whereNull('parent_id')
                                            ->orWhereHas('parentWithTrashed', function (Builder $parent) use ($viewer): void {
                                                $parent->where(function (Builder $reachable) use ($viewer): void {
                                                    $reachable->visibleTo($viewer)
                                                        ->orWhere(function (Builder $parentTombstone) use ($viewer): void {
                                                            $parentTombstone->tombstoneVisibleTo($viewer)
                                                                ->where(fn (Builder $state): Builder => $state
                                                                    ->whereNotNull('removed_at')
                                                                    ->orWhereNotNull('deleted_at'));
                                                        });
                                                });
                                            });
                                    });
                            })
                            // Author deletion is gone entirely unless a root is
                            // still needed to hold another user's reply in place.
                            ->orWhere(function (Builder $deleted) use ($viewer): void {
                                $deleted->tombstoneVisibleTo($viewer)
                                    ->whereNotNull('deleted_at')
                                    ->whereNull('parent_id')
                                    ->whereHas('replies', function (Builder $replies) use ($viewer): void {
                                        $replies->where(function (Builder $visible) use ($viewer): void {
                                            $visible->threadVisibleTo($viewer)
                                                ->orWhere(function (Builder $removedReply) use ($viewer): void {
                                                    $removedReply->tombstoneVisibleTo($viewer)->whereNotNull('removed_at');
                                                });
                                        });
                                    });
                            });
                    });
            })
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $comments->map(function (PostComment $comment) use ($post, $viewer): array {
                // Every comment here belongs to $post, so seed the relation to
                // keep the delete-policy check from re-querying the post per row.
                $comment->setRelation('post', $post);

                if ($comment->trashed() || $comment->removed_at !== null) {
                    return PostCommentPresenter::tombstone($comment);
                }

                return PostCommentPresenter::view($comment, $this->mediaResponder, $viewer) + [
                    'can_delete' => $viewer !== null && (Gate::forUser($viewer)->allows('delete', $comment)
                        || Gate::forUser($viewer)->allows('removeFromPost', $comment)),
                ];
            })->values(),
        ])->withHeaders($headers);
    }

    public function store(CommentRequest $request, Post $post): JsonResponse
    {
        // Visibility of $post is authorized in CommentRequest::authorize(), before
        // validation, so a hidden post 404s rather than leaking via a 422.

        /** @var User $user */
        $user = $request->user();
        $post->loadMissing('character.profilePicture');

        $comment = $post->comments()->make([
            'user_id' => $user->id,
            'body' => $request->validated('body'),
            'parent_id' => $this->resolveParentId($request, $post),
        ]);
        // Auto-approve: short replies publish immediately and are moderated
        // reactively. The moderation column is not mass-assignable.
        $comment->moderation_status = ModerationStatus::Approved;
        $comment->save();

        // Notify the post's author of the new comment, never for their own.
        if ($post->user_id !== $user->id && $post->user?->notify_post_comment) {
            $post->user->notify(new PostCommentedOn($post, $user));
        }

        $comment->load(['user:id,name,display_name,profile_picture_media_id', 'user.profilePicture']);
        $comment->setRelation('post', $post);

        return response()->json([
            'success' => true,
            'data' => PostCommentPresenter::view($comment, $this->mediaResponder, $user)
                + ['can_delete' => Gate::forUser($user)->allows('delete', $comment)],
        ], 201);
    }

    public function destroy(Request $request, Post $post, PostComment $comment): JsonResponse
    {
        if ($comment->post_id !== $post->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $user = $request->user();
        if ($user !== null && Gate::forUser($user)->allows('delete', $comment)) {
            $comment->delete();
        } elseif ($user !== null && Gate::forUser($user)->allows('removeFromPost', $comment)) {
            $comment->forceFill(['removed_by_user_id' => $user->id, 'removed_at' => now()])->save();
        } else {
            abort(404, 'Not found.');
        }

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

        // The parent must be visible to the replier — replying to a moderated-away
        // comment would surface a reply whose parent the viewer cannot see.
        $parent = PostComment::query()->visibleTo($request->user())->find($parentId);
        if ($parent === null || $parent->post_id !== $post->id || $parent->parent_id !== null) {
            throw ValidationException::withMessages(['parent_id' => 'You can only reply to a comment on this post.']);
        }

        return (int) $parentId;
    }
}
