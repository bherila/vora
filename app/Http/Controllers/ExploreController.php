<?php

namespace App\Http\Controllers;

use App\Enums\ModerationStatus;
use App\Http\Requests\Media\ListMediaRequest;
use App\Models\Media;
use App\Services\Media\MediaResponseService;
use App\Support\MediaFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Cross-user media exploration. This is the privacy-aware counterpart to the
 * owner's library: it lists media uploaded by *anyone*, but only rows the viewer
 * is allowed to discover.
 *
 * The discovery filters (type, interests) and the response shape are shared with
 * {@see MediaController} via {@see MediaFilter} and {@see MediaResponseService};
 * the only thing that differs here is the base scoping — `visibleTo` (owner +
 * admin + any-user visibility, unlisted excluded) intersected with approved
 * moderation. Keeping that intersection in one query is what makes the privacy
 * rule auditable as search/exploration grows.
 */
class ExploreController extends Controller
{
    public function __construct(
        private readonly MediaResponseService $responder,
    ) {}

    /**
     * The exploration page (results are fetched client-side from `apiIndex`).
     */
    public function page(): View
    {
        return view('user.explore');
    }

    /**
     * List media discoverable by the current viewer, newest first, paginated.
     */
    public function apiIndex(ListMediaRequest $request): JsonResponse
    {
        $query = Media::query()
            ->visibleTo($request->user())
            ->moderationStatus(ModerationStatus::Approved)
            ->where('upload_status', 'ready')
            ->with('interests')
            ->latest();

        $paginator = MediaFilter::fromRequest($request)
            ->applyTo($query)
            ->paginate((int) config('media.page_size', 24));

        return response()->json([
            'success' => true,
            ...$this->responder->page($paginator),
        ]);
    }
}
