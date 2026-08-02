<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ModerationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ModeratePostRequest;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use App\Support\PaginationMeta;
use App\Support\PostPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPostController extends Controller
{
    public function __construct(private readonly MediaResponseService $mediaResponder) {}

    /**
     * JSON list of posts for reactive moderation. Includes moderation fields
     * and ignores owner/audience restrictions for admin review.
     */
    public function apiIndex(Request $request): JsonResponse
    {
        $status = $request->query('status');
        /** @var User $admin */
        $admin = $request->user();

        $paginator = Post::query()
            ->with(['user', 'character.profilePicture', 'contextInterest', 'attachments.attachable'])
            ->withAdminEngagementCounts($admin)
            ->when(in_array($status, ModerationStatus::values(), true), function (Builder $q) use ($status): void {
                $q->where('moderation_status', $status);
            })
            ->orderByRaw('CASE WHEN moderation_status = ? THEN 0 ELSE 1 END', [ModerationStatus::Pending->value])
            ->latest()
            ->paginate((int) config('media.page_size', 24));

        $data = collect($paginator->items())
            ->map(fn (Post $post): array => PostPresenter::adminView($post, $admin, $this->mediaResponder))
            ->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => PaginationMeta::from($paginator),
        ]);
    }

    /**
     * Approve or reject a post.
     */
    public function moderate(ModeratePostRequest $request, Post $post): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $notes = $request->validated()['notes'] ?? null;

        if ($request->validated()['action'] === 'approve') {
            $post->approve($admin, $notes);
        } else {
            $post->reject($admin, $notes);
        }

        $post = Post::query()
            ->whereKey($post->id)
            ->with(['user', 'character.profilePicture', 'contextInterest', 'attachments.attachable'])
            ->withAdminEngagementCounts($admin)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => PostPresenter::adminView($post, $admin, $this->mediaResponder),
        ]);
    }
}
