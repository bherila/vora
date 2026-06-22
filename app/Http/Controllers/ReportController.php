<?php

namespace App\Http\Controllers;

use App\Enums\ReportStatus;
use App\Http\Requests\Report\StoreReportRequest;
use App\Models\Report;
use App\Models\User;
use App\Services\Favorites\FavoriteService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(private readonly FavoriteService $favorites) {}

    /**
     * File an abuse report against a media item, story, or post. The reporter
     * must be able to see the item (its own privacy decides that) and cannot
     * report their own content. Re-reporting an item with an open report is a
     * no-op, so the dialog can be submitted safely more than once.
     */
    public function store(StoreReportRequest $request): JsonResponse
    {
        $user = $request->user();
        $item = $this->favorites->resolve($request->validated('type'), (int) $request->validated('id'));

        if ($item === null) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        if (! $this->favorites->canViewerSee($user, $item)) {
            return response()->json(['success' => false, 'message' => 'You cannot report this.'], 403);
        }

        $owner = $this->favorites->ownerOf($item);
        if ($owner instanceof User && $owner->id === $user->id) {
            return response()->json(['success' => false, 'message' => 'You cannot report your own content.'], 422);
        }

        Report::query()->firstOrCreate(
            [
                'reporter_user_id' => $user->id,
                'reportable_type' => $item->getMorphClass(),
                'reportable_id' => $item->getKey(),
                'status' => ReportStatus::Open->value,
            ],
            [
                'reason' => $request->validated('reason'),
                'details' => $request->validated('details'),
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Thanks — our team will review this report.',
        ], 201);
    }
}
