<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Services\FileStorageService;
use App\Support\MediaPresenter;
use App\Support\PaginationMeta;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Turns Media models into API payloads. Centralising this keeps every surface —
 * the owner's library, single-item views, and cross-user exploration — emitting
 * the exact same shape (signed original URL where allowed, signed thumbnail URL,
 * HLS status) so the listings cannot drift apart.
 */
class MediaResponseService
{
    public function __construct(
        private readonly FileStorageService $storage,
        private readonly HlsService $hls,
    ) {}

    /**
     * Compute the signed view URLs and (for videos) HLS playback status for one
     * item. Pass $resolveHls=false in listings to skip the per-item R2 read that
     * resolves a transcoder content id.
     *
     * @return array{url: ?string, thumbnail_url: ?string, video: ?array<string, mixed>}
     */
    public function extras(Media $media, bool $resolveHls = true, bool $includeOriginalVideoUrl = false): array
    {
        $extras = ['url' => null, 'thumbnail_url' => null, 'video' => null];

        if (! $media->isReady()) {
            return $extras;
        }

        $ttl = (int) config('media.view_url_ttl', 60);

        $shouldSignOriginal = ! $media->type->isVideo() || $includeOriginalVideoUrl;
        if ($shouldSignOriginal) {
            $extras['url'] = $this->storage->getSignedViewUrl(
                $media->disk,
                $media->playbackObjectKey(),
                $ttl,
                $media->mime_type,
            );
        }

        $thumbnailKey = $media->playbackThumbnailKey();
        if ($thumbnailKey !== null) {
            $extras['thumbnail_url'] = $this->storage->getSignedViewUrl(
                (string) config('media.thumbnail_disk'),
                $thumbnailKey,
                $ttl,
                'image/jpeg',
            );
        }

        if ($media->type->isVideo()) {
            $extras['video'] = $this->hls->status($media, $resolveHls);
        }

        return $extras;
    }

    /**
     * Present a single owned/visible item (no moderation fields).
     *
     * @return array<string, mixed>
     */
    public function item(Media $media, bool $resolveHls = true, bool $includeOriginalVideoUrl = false): array
    {
        return MediaPresenter::ownerView($media, $this->extras($media, $resolveHls, $includeOriginalVideoUrl));
    }

    /**
     * Present a paginated listing as the standard `{ data, meta }` envelope.
     * Listings never resolve HLS per item (no per-row R2 read).
     *
     * @param  LengthAwarePaginator<int, Media>  $paginator
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function page(LengthAwarePaginator $paginator, bool $includeOriginalVideoUrls = false): array
    {
        $data = collect($paginator->items())
            ->map(fn (Media $m): array => $this->item($m, resolveHls: false, includeOriginalVideoUrl: $includeOriginalVideoUrls))
            ->values()
            ->all();

        return [
            'data' => $data,
            'meta' => PaginationMeta::from($paginator),
        ];
    }
}
