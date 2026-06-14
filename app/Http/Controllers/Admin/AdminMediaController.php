<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ModerationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ModerateMediaRequest;
use App\Models\Media;
use App\Services\FileStorageService;
use App\Services\Media\HlsService;
use App\Support\MediaPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminMediaController extends Controller
{
    public function __construct(
        private readonly HlsService $hls,
        private readonly FileStorageService $storage,
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

        $items = Media::query()
            ->with(['interests', 'user'])
            ->when(in_array($status, ModerationStatus::values(), true), function (Builder $q) use ($status): void {
                $q->where('moderation_status', $status);
            })
            // Pending first so the review queue surfaces new uploads, then newest.
            ->orderByRaw('CASE WHEN moderation_status = ? THEN 0 ELSE 1 END', [ModerationStatus::Pending->value])
            ->latest()
            ->get()
            ->map(fn (Media $m): array => MediaPresenter::adminView($m, $this->extrasFor($m)));

        return response()->json(['success' => true, 'data' => $items]);
    }

    /**
     * Approve or reject a media item.
     */
    public function moderate(ModerateMediaRequest $request, Media $media): JsonResponse
    {
        $admin = $request->user();
        $notes = $request->validated()['notes'] ?? null;

        if ($request->validated()['action'] === 'approve') {
            $media->approve($admin, $notes);
        } else {
            $media->reject($admin, $notes);
        }

        $media->load(['interests', 'user']);

        return response()->json(['success' => true, 'data' => MediaPresenter::adminView($media, $this->extrasFor($media))]);
    }

    /**
     * @return array{url: ?string, video: ?array<string, mixed>}
     */
    private function extrasFor(Media $media): array
    {
        $extras = ['url' => null, 'video' => null];

        if (! $media->isReady()) {
            return $extras;
        }

        $extras['url'] = $this->storage->getSignedViewUrl(
            $media->disk,
            $media->object_key,
            (int) config('media.view_url_ttl', 60),
            $media->mime_type,
        );

        if ($media->type->isVideo()) {
            $extras['video'] = $this->hls->status($media);
        }

        return $extras;
    }
}
