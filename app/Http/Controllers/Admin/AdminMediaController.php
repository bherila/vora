<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ModerationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ModerateMediaRequest;
use App\Models\Media;
use App\Services\Media\AdminMediaResponseService;
use App\Services\Media\GlobalMediaDuplicateService;
use App\Services\Media\MediaModerationService;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminMediaController extends Controller
{
    public function __construct(
        private readonly AdminMediaResponseService $responder,
        private readonly GlobalMediaDuplicateService $duplicates,
        private readonly MediaModerationService $moderation,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Admin media review page (mounts the React admin UI).
     */
    public function index(): View
    {
        return view('admin.media');
    }

    /**
     * JSON list of ALL media for review, regardless of owner, privacy, or
     * review state. Optionally filtered by ?status=. Includes the internal
     * moderation fields (admin-only view).
     */
    public function apiIndex(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $paginator = Media::query()
            ->with(['interests', 'user'])
            // Only uploaded content is reviewable. Rows still pending their R2
            // PUT must never be approvable (the owner could complete the upload
            // afterwards and make unreviewed content visible).
            ->where('upload_status', 'ready')
            ->when(in_array($status, ModerationStatus::values(), true), function (Builder $q) use ($status): void {
                $q->where('moderation_status', $status);
            })
            // Pending first so the review queue surfaces new uploads, then newest.
            ->orderByRaw('CASE WHEN moderation_status = ? THEN 0 ELSE 1 END', [ModerationStatus::Pending->value])
            ->latest()
            ->paginate((int) config('media.page_size', 24));

        $page = $this->responder->page($paginator, resolveHls: false);
        $summaries = $this->duplicates->summariesFor(collect($paginator->items()));
        $page['data'] = collect($page['data'])->map(function (array $item) use ($summaries): array {
            $item['cross_account_duplicates'] = $summaries[$item['id']] ?? [
                'other_account_count' => 0,
                'match_count' => 0,
                'matches' => [],
            ];

            return $item;
        })->all();

        return response()->json([
            'success' => true,
            'duplicate_scan' => $this->duplicates->scanStatus(),
            'settings' => ['pre_review_video_processing' => $this->settings->preReviewVideoProcessing()],
            ...$page,
        ]);
    }

    /**
     * Approve or reject a media item.
     */
    public function moderate(ModerateMediaRequest $request, Media $media): JsonResponse
    {
        // Moderate a fresh, locked row: the scheduled pre-review preparation
        // copies objects under the same lock, and a route-bound model loaded
        // before it finished would miss (and so never delete) those copies.
        $moderated = DB::transaction(function () use ($request, $media): JsonResponse|Media {
            $media = Media::withTrashed()->whereKey($media->id)->lockForUpdate()->first();
            if (! $media instanceof Media) {
                abort(404);
            }

            // Guard the same bypass as the listing: never let a not-yet-uploaded
            // row be approved/rejected.
            if (! $media->isReady()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This upload has not completed yet and cannot be reviewed.',
                ], 422);
            }

            $admin = $request->user();
            $notes = $request->validated()['notes'] ?? null;

            if ($request->validated()['action'] === 'approve') {
                if (! $this->moderation->approve($media, $admin, $notes)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Media could not be copied into reviewed storage.',
                    ], 422);
                }
            } else {
                $this->moderation->reject($media, $admin, $notes);
            }

            return $media;
        });
        if ($moderated instanceof JsonResponse) {
            return $moderated;
        }

        $moderated->load(['interests', 'user']);

        $data = $this->responder->item($moderated);
        $data['cross_account_duplicates'] = $this->duplicates->summariesFor(collect([$moderated]))[$moderated->id];

        return response()->json(['success' => true, 'data' => $data]);
    }
}
