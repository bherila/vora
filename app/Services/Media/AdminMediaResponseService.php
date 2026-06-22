<?php

namespace App\Services\Media;

use App\Enums\MediaType;
use App\Models\Media;
use App\Services\FileStorageService;
use App\Support\MediaPresenter;
use App\Support\PaginationMeta;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AdminMediaResponseService
{
    public function __construct(
        private readonly HlsService $hls,
        private readonly PdqImageService $pdq,
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
        } elseif ($media->type === MediaType::Photo) {
            // Resolve the worker's PDQ hash and flag a per-owner near-duplicate.
            // Unlike HLS this runs for the review *list* too (not just single
            // items): admins act on the list and the duplicate flag must be
            // populated before they moderate. It stays cheap — once a hash is
            // cached the call is a no-op, and an unresolved one re-checks the
            // results bucket at most once per recheck interval (and not at all
            // when the results disk is unconfigured).
            $this->pdq->ensureResolved($media);
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
