<?php

namespace App\Http\Controllers;

use App\Enums\ReportStatus;
use App\Http\Requests\Report\StoreReportRequest;
use App\Models\Report;
use App\Models\User;
use App\Notifications\AbuseReportFiled;
use App\Services\Favorites\FavoriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Notification;

class ReportController extends Controller
{
    public function __construct(private readonly FavoriteService $favorites) {}

    /**
     * File an abuse report against content, a character, or a user profile. The
     * reporter must be able to see the target (its own privacy decides that) and
     * cannot report their own content or profiles. Re-reporting a target with an
     * open report is a no-op, so the dialog can be submitted safely more than once.
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

        $report = Report::query()->firstOrCreate(
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

        // Alert admins on a genuinely new report (a repeat is a silent no-op).
        if ($report->wasRecentlyCreated) {
            $admins = User::query()
                ->where(fn ($q) => $q->where('is_admin', true)->orWhere('id', 1))
                ->whereNotNull('approved_at')
                ->active()
                ->get();
            Notification::send($admins, new AbuseReportFiled($report));
        }

        return response()->json([
            'success' => true,
            'message' => 'Thanks — our team will review this report.',
        ], 201);
    }
}
