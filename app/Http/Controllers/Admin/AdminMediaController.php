<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ModerationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ModerateMediaRequest;
use App\Models\Media;
use App\Services\FileStorageService;
use App\Services\Media\HlsService;
use App\Services\Media\MediaModerationService;
use App\Support\MediaPresenter;
use App\Support\PaginationMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminMediaController extends Controller
{
    public function __construct(
        private readonly HlsService $hls,
        private readonly FileStorageService $storage,
        private readonly MediaModerationService $moderation,
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

        $data = collect($paginator->items())
            // resolveHls: false — the review queue must not do a per-item R2 read.
            ->map(fn (Media $m): array => MediaPresenter::adminView($m, $this->extrasFor($m, resolveHls: false)))
            ->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => PaginationMeta::from($paginator),
        ]);
    }

    /**
     * Approve or reject a media item.
     */
    public function moderate(ModerateMediaRequest $request, Media $media): JsonResponse
    {
        // Guard the same bypass as the listing: never let a not-yet-uploaded row
        // be approved/rejected.
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

        $media->load(['interests', 'user']);

        return response()->json(['success' => true, 'data' => MediaPresenter::adminView($media, $this->extrasFor($media))]);
    }

    /**
     * @return array{url: ?string, download_url: ?string, thumbnail_url: ?string, video: ?array<string, mixed>}
     */
    private function extrasFor(Media $media, bool $resolveHls = true): array
    {
        $extras = ['url' => null, 'download_url' => null, 'thumbnail_url' => null, 'video' => null];

        if (! $media->isReady()) {
            return $extras;
        }

        $playbackKey = $media->playbackObjectKey();
        $ttl = (int) config('media.view_url_ttl', 60);

        $extras['url'] = $this->storage->getSignedViewUrl(
            $media->disk,
            $playbackKey,
            $ttl,
            $media->mime_type,
        );

        if ($media->type->isVideo()) {
            $extras['download_url'] = $this->storage->getSignedDownloadUrl(
                $media->disk,
                $playbackKey,
                $media->original_filename,
                $ttl,
            );
        }

        // Sign the client-supplied thumbnail/poster too. It is exactly what the
        // owner library and Explore grids display, so the reviewer must see and
        // approve it here — otherwise an arbitrary uploaded JPEG would reach
        // discovery surfaces without ever being reviewed.
        $thumbnailKey = $media->playbackThumbnailKey();
        if ($thumbnailKey !== null) {
            $extras['thumbnail_url'] = $this->storage->getSignedViewUrl(
                (string) config('media.thumbnail_disk'),
                $thumbnailKey,
                (int) config('media.view_url_ttl', 60),
                'image/jpeg',
            );
        }

        if ($media->type->isVideo()) {
            $extras['video'] = $this->hls->status($media, $resolveHls);
        }

        return $extras;
    }
}
