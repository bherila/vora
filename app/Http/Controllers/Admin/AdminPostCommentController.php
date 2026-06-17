<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ModerationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ModeratePostCommentRequest;
use App\Models\PostComment;
use App\Models\User;
use App\Support\PaginationMeta;
use App\Support\PostCommentPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPostCommentController extends Controller
{
    /**
     * JSON list of comments for reactive moderation.
     */
    public function apiIndex(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $paginator = PostComment::query()
            ->with(['user:id,name,display_name', 'post.user:id,name,display_name'])
            ->when(in_array($status, ModerationStatus::values(), true), function (Builder $q) use ($status): void {
                $q->where('moderation_status', $status);
            })
            ->orderByRaw('CASE WHEN moderation_status = ? THEN 0 ELSE 1 END', [ModerationStatus::Pending->value])
            ->latest()
            ->paginate((int) config('media.page_size', 24));

        $data = collect($paginator->items())
            ->map(fn (PostComment $comment): array => PostCommentPresenter::adminView($comment))
            ->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => PaginationMeta::from($paginator),
        ]);
    }

    /**
     * Approve or reject a comment.
     */
    public function moderate(ModeratePostCommentRequest $request, PostComment $postComment): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $notes = $request->validated()['notes'] ?? null;

        if ($request->validated()['action'] === 'approve') {
            $postComment->approve($admin, $notes);
        } else {
            $postComment->reject($admin, $notes);
        }

        $postComment->load(['user:id,name,display_name', 'post.user:id,name,display_name']);

        return response()->json([
            'success' => true,
            'data' => PostCommentPresenter::adminView($postComment),
        ]);
    }
}
