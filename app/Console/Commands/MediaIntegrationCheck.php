<?php

namespace App\Console\Commands;

use App\Enums\MediaType;
use App\Models\LibraryItem;
use App\Models\Media;
use App\Services\FileStorageService;
use App\Services\Library\LibraryAccess;
use App\Services\Media\HlsService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

final class MediaIntegrationCheck extends Command
{
    private const MAX_MANIFEST_BYTES = 65536;

    private const MAX_HLS_OBJECTS = 128;

    protected $signature = 'ops:media-integration-check
        {--read-storage : Explicitly permit reads of the selected test objects}
        {--photo= : Test photo media ULID}
        {--video= : Test video media ULID}
        {--saved-photo= : Independent saved photo Library item ULID}
        {--saved-video= : Independent saved video Library item ULID}
        {--expect-uploader-removed : Require the original uploader to have been permanently removed}
        {--json : Emit machine-readable results without storage or account identifiers}';

    protected $description = 'Read-only checks of selected Library test references, object storage, and HLS artifacts';

    /** @var array<string, bool> */
    private array $checks = [];

    public function handle(FileStorageService $storage, HlsService $hls, LibraryAccess $access): int
    {
        $this->checks = [];
        if (! $this->option('read-storage')) {
            return $this->report('explicit_storage_opt_in_required');
        }

        foreach (['photo', 'video', 'saved-photo', 'saved-video'] as $option) {
            if (! Str::isUlid((string) $this->option($option))) {
                return $this->report('four_test_ulids_required');
            }
        }

        try {
            foreach (['photo' => MediaType::Photo, 'video' => MediaType::Video] as $name => $type) {
                $media = Media::withTrashed()->where('ulid', $this->option($name))->first();
                $item = LibraryItem::with('owner')->where('ulid', $this->option('saved-'.$name))->first();
                $valid = $media !== null && $media->type === $type && $media->isReady() && $media->isApprovedContent();
                $this->checks[$name.'_ready'] = $valid;
                $this->checks[$name.'_saved_reference'] = $valid && $item !== null
                    && $item->source === 'saved' && $item->media_id === $media->id
                    && $item->owner !== null && $item->owner->isActive() && $item->owner->isApproved()
                    && $item->user_id !== $media->user_id && $access->canViewMedia($item->owner, $media);

                if (! $valid) {
                    continue;
                }

                if ($this->option('expect-uploader-removed')) {
                    $this->checks[$name.'_uploader_removed'] = $media->user_id === null && $media->trashed();
                }

                $this->check($name.'_original', fn (): bool => $this->checkOriginal($storage, $media, $media->object_key));
                $this->check($name.'_reviewed', fn (): bool => $media->reviewed_object_key !== null
                    && $media->reviewed_object_key !== $media->object_key
                    && $this->checkOriginal($storage, $media, $media->reviewed_object_key));

                if ($type === MediaType::Video) {
                    $this->check('video_hls_artifacts', fn (): bool => $this->checkHls($storage, $hls, $media));
                }
            }
        } catch (Throwable) {
            // Storage exceptions can contain signed URLs, credentials, or keys.
            return $this->report('selected_test_resources_unavailable');
        }

        return $this->report();
    }

    private function checkOriginal(FileStorageService $storage, Media $media, string $key): bool
    {
        return $media->size_bytes !== null && $media->size_bytes > 0
            && $storage->getFileSize($media->disk, $key) === $media->size_bytes
            && $this->read($storage, $media->disk, $key, 1) !== null;
    }

    private function checkHls(FileStorageService $storage, HlsService $hls, Media $media): bool
    {
        $disk = (string) config('media.hls_disk', 'hls');
        $mapping = $this->read($storage, $disk, 'mappings/'.$media->playbackObjectKey().'.json', self::MAX_MANIFEST_BYTES, true);
        $contentId = $mapping === null ? null : (json_decode($mapping, true)['contentId'] ?? null);
        if (! is_string($contentId) || preg_match('/^[A-Za-z0-9:_-]+$/D', $contentId) !== 1) {
            return false;
        }
        if ($media->hls_content_id !== null && $media->hls_content_id !== $contentId) {
            return false;
        }

        // Never call ensureResolved(): it persists HLS state. Only this in-memory
        // model is updated, so the command issues no database/storage writes.
        $media->hls_content_id = $contentId;
        $pending = ['master.m3u8'];
        $visited = [];
        $segments = 0;
        while ($pending !== []) {
            $path = array_shift($pending);
            if (isset($visited[$path])) {
                continue;
            }
            if (count($visited) >= self::MAX_HLS_OBJECTS || ! $hls->isSafeRelativePath($path)) {
                return false;
            }
            $visited[$path] = true;
            $key = 'by-id/'.$contentId.'/'.$path;
            if (! $hls->isManifestPath($path)) {
                if ($this->read($storage, $disk, $key, 64) === null || $hls->segmentUrl($media, $path) === null) {
                    return false;
                }
                $segments++;

                continue;
            }
            $size = $storage->getFileSize($disk, $key);
            if ($size === null || $size < 1 || $size > self::MAX_MANIFEST_BYTES) {
                return false;
            }
            $manifest = $hls->manifest($media, $path, function (string $child) use (&$pending): string {
                if (count($pending) >= self::MAX_HLS_OBJECTS) {
                    throw new \RuntimeException('Artifact limit exceeded.');
                }
                $pending[] = $child;

                return '/integration-check';
            });
            if ($manifest === null || ! str_starts_with(ltrim($manifest['body']), '#EXTM3U')) {
                return false;
            }
        }

        return $segments > 0;
    }

    private function read(FileStorageService $storage, string $disk, string $key, int $limit, bool $whole = false): ?string
    {
        $stream = $storage->readStream($disk, $key);
        if ($stream === null) {
            return null;
        }
        try {
            $bytes = stream_get_contents($stream, $limit + (int) $whole);

            return is_string($bytes) && $bytes !== '' && (! $whole || strlen($bytes) <= $limit) ? $bytes : null;
        } finally {
            fclose($stream);
        }
    }

    private function check(string $name, callable $check): void
    {
        try {
            $this->checks[$name] = $check();
        } catch (Throwable) {
            $this->checks[$name] = false;
        }
    }

    private function report(?string $error = null): int
    {
        $healthy = $error === null && $this->checks !== [] && ! in_array(false, $this->checks, true);
        $payload = ['healthy' => $healthy, 'checks' => $this->checks];
        if ($error !== null) {
            $payload['error'] = $error;
        }
        $this->line(json_encode($payload, JSON_THROW_ON_ERROR | ($this->option('json') ? 0 : JSON_PRETTY_PRINT)));

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
