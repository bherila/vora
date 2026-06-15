<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ModerationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ModerateStoryRequest;
use App\Models\Story;
use App\Services\Story\StoryService;
use App\Support\PaginationMeta;
use App\Support\StoryPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminStoryController extends Controller
{
    public function __construct(private readonly StoryService $stories) {}

    /**
     * Admin story review page (mounts the React admin UI).
     */
    public function index(): View
    {
        return view('admin.stories');
    }

    /**
     * JSON list of ALL stories for review, optionally filtered by ?status=.
     * Includes the internal moderation fields (admin-only view).
     */
    public function apiIndex(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $paginator = Story::query()
            ->with(['user', 'interests', 'involvements.involvable', 'authors.user'])
            ->withCount('nodes')
            ->when(in_array($status, ModerationStatus::values(), true), function (Builder $q) use ($status): void {
                $q->where('moderation_status', $status);
            })
            ->orderByRaw('CASE WHEN moderation_status = ? THEN 0 ELSE 1 END', [ModerationStatus::Pending->value])
            ->latest()
            ->paginate((int) config('media.page_size', 24));

        $data = collect($paginator->items())
            ->map(fn (Story $story): array => StoryPresenter::adminView($story))
            ->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => PaginationMeta::from($paginator),
        ]);
    }

    /**
     * Approve or reject a story.
     */
    public function moderate(ModerateStoryRequest $request, Story $story): JsonResponse
    {
        $admin = $request->user();
        $notes = $request->validated()['notes'] ?? null;

        if ($request->validated()['action'] === 'approve') {
            $story->approve($admin, $notes);
        } else {
            $story->reject($admin, $notes);
        }

        return response()->json([
            'success' => true,
            'data' => StoryPresenter::adminView($this->stories->loadForPresentation($story)),
        ]);
    }
}
