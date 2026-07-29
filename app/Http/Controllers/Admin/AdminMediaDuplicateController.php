<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminDuplicateClustersRequest;
use App\Models\Media;
use App\Models\Report;
use App\Services\Media\AdminMediaResponseService;
use App\Services\Media\GlobalMediaDuplicateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminMediaDuplicateController extends Controller
{
    public function __construct(
        private readonly GlobalMediaDuplicateService $duplicates,
        private readonly AdminMediaResponseService $responder,
    ) {}

    public function index(): View
    {
        return view('admin.media-duplicates');
    }

    public function apiIndex(AdminDuplicateClustersRequest $request): JsonResponse
    {
        $sort = (string) $request->validated('sort', 'size_desc');
        $clusters = collect($this->duplicates->clusters($sort))
            ->map(function (array $cluster): array {
                $cluster['media'] = collect($cluster['media'])
                    ->map(fn (Media $media): array => $this->responder->item($media))
                    ->all();

                return $cluster;
            })
            ->all();

        return response()->json(['success' => true, 'data' => $clusters]);
    }

    public function queueReview(Request $request, Media $media): JsonResponse
    {
        $summary = $this->duplicates->summariesFor(collect([$media]))[$media->id];
        if ($summary['match_count'] === 0) {
            return response()->json([
                'success' => false,
                'message' => 'This media has no current cross-account PDQ match.',
            ], 422);
        }

        $report = Report::query()->firstOrCreate(
            [
                'reporter_user_id' => $request->user()->id,
                'reportable_type' => $media->getMorphClass(),
                'reportable_id' => $media->id,
                'status' => ReportStatus::Open->value,
            ],
            [
                'reason' => ReportReason::Spam->value,
                'details' => 'Cross-account near-duplicate identified during PDQ cluster review.',
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Media queued for abuse review.',
        ], $report->wasRecentlyCreated ? 201 : 200);
    }
}
