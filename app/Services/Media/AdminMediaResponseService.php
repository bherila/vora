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
        $items = collect($paginator->items());

        // Build every item's extras first. This triggers PDQ resolution, which can
        // flag a *different* row in this same page as a near-duplicate (the
        // older/newer pair in MediaDuplicateService::flagPdqDuplicate writes to the
        // freshly fetched candidate, not the instance the paginator holds). Doing it
        // in one pass means a row already serialized would miss a flag set while a
        // later row resolved — so capture extras now and reconcile before presenting.
        $extras = $items
            ->mapWithKeys(fn (Media $media): array => [$media->id => $this->extras($media, $resolveHls, $downloadAll)])
            ->all();

        // Reload duplicate_of_media_id from the database in one query and copy it back
        // onto the loaded instances, so a flag set on another page row during
        // resolution above is reflected instead of the stale in-memory null.
        $duplicateIds = Media::query()
            ->whereKey($items->pluck('id'))
            ->pluck('duplicate_of_media_id', 'id');
        $items->each(fn (Media $media) => $media->setAttribute(
            'duplicate_of_media_id',
            $duplicateIds->get($media->id),
        ));

        $data = $items
            ->map(fn (Media $media): array => MediaPresenter::adminView($media, $extras[$media->id]))
            ->values()
            ->all();

        return [
            'data' => $data,
            'meta' => PaginationMeta::from($paginator),
        ];
    }
}
