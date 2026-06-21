<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Services\FileStorageService;
use App\Support\MediaPresenter;
use App\Support\PaginationMeta;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AdminMediaResponseService
{
    public function __construct(
        private readonly HlsService $hls,
        private readonly FileStorageService $storage,
    ) {}

    /**
     * @return array{url: ?string, download_url: ?string, thumbnail_url: ?string, video: ?array<string, mixed>}
     */
    public function extras(Media $media, bool $resolveHls = true, bool $downloadAll = false): array
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

        if ($downloadAll || $media->type->isVideo()) {
            $extras['download_url'] = $this->storage->getSignedDownloadUrl(
                $media->disk,
                $playbackKey,
                $media->original_filename,
                $ttl,
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
     * @return array<string, mixed>
     */
    public function item(Media $media, bool $resolveHls = true, bool $downloadAll = false): array
    {
        return MediaPresenter::adminView($media, $this->extras($media, $resolveHls, $downloadAll));
    }

    /**
     * @param  LengthAwarePaginator<int, Media>  $paginator
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function page(LengthAwarePaginator $paginator, bool $resolveHls = false, bool $downloadAll = false): array
    {
        $data = collect($paginator->items())
            ->map(fn (Media $media): array => $this->item($media, $resolveHls, $downloadAll))
            ->values()
            ->all();

        return [
            'data' => $data,
            'meta' => PaginationMeta::from($paginator),
        ];
    }
}
