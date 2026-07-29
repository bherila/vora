<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Services\FileStorageService;
use Illuminate\Support\Str;

/**
 * Bridges the app to the out-of-band s3-hls transcoder's output bucket (the
 * `hls` disk). For every playable video object the transcoder writes a mapping
 * at `mappings/<object-key>.json` whose `contentId` points at the
 * content-addressed output tree `by-id/<contentId>/…` (master + per-rung
 * playlists + fMP4 segments). Approved media resolve from the immutable
 * reviewed copy, not the client-overwritable upload key.
 *
 * Playback is served through an authenticated proxy (see MediaController@streamHls):
 * small `.m3u8` manifests are fetched and their child URIs rewritten back through
 * the proxy (so access stays gated and relative URLs resolve), while heavy segment
 * requests are 302-redirected to short-lived presigned URLs so R2 — not the app —
 * carries the bandwidth.
 */
class HlsService
{
    /** Re-check interval for a not-yet-ready video's mapping. */
    private const RECHECK_AFTER_MINUTES = 2;

    /** Lifetime of presigned segment URLs handed to the browser. */
    private const SEGMENT_URL_TTL_MINUTES = 30;

    public function __construct(
        private readonly FileStorageService $storage,
        private readonly MediaDuplicateService $duplicates,
    ) {}

    private function disk(): string
    {
        return (string) config('media.hls_disk', 'hls');
    }

    /**
     * Resolve whether HLS exists for this video, caching the content id on the
     * row. Cheap and idempotent: returns immediately once known-ready, otherwise
     * looks up the mapping at most once per RECHECK_AFTER_MINUTES.
     */
    public function ensureResolved(Media $video): bool
    {
        if (! $video->type->isVideo()) {
            return false;
        }

        if ($video->isHlsReady()) {
            return true;
        }

        if (! $this->isRecheckDue($video)) {
            return false;
        }

        $contentId = $this->lookupContentId($video->playbackObjectKey());

        $video->hls_checked_at = now();
        if ($contentId !== null) {
            $video->hls_content_id = $contentId;
        }
        $video->saveQuietly();

        if ($contentId !== null) {
            // The transcoder's content id is a content-aware hash: flag this video
            // as a likely duplicate of an earlier one the owner uploaded that
            // resolved to the same content (e.g. a re-encode of the same clip).
            $this->duplicates->flagContentDuplicate($video);
        }

        return $contentId !== null;
    }

    /**
     * Status payload for serialization: processing until the mapping exists, then
     * ready with the proxy master-playlist URL.
     *
     * Pass $resolve = false on list endpoints to avoid a per-item R2 mapping read:
     * the status is then derived solely from the already-cached content id, so a
     * not-yet-resolved video reads as "processing" until it is opened (the single
     * show/stream endpoints resolve it). This keeps listings at O(1) network I/O.
     *
     * @return array{status: string, master_url: ?string}
     */
    public function status(Media $video, bool $resolve = true): array
    {
        if (! $video->type->isVideo()) {
            return ['status' => 'not_applicable', 'master_url' => null];
        }

        $ready = $resolve ? $this->ensureResolved($video) : $video->isHlsReady();

        if (! $ready) {
            return ['status' => 'processing', 'master_url' => null];
        }

        return [
            'status' => 'ready',
            // Keep this app-relative so playback follows the browser's actual
            // origin instead of a potentially stale deploy-time APP_URL.
            'master_url' => route('media.hls', ['media' => $video->id, 'path' => 'master.m3u8'], false),
        ];
    }

    /**
     * Build a proxied manifest body for a ready video, with every child URI
     * rewritten to an absolute proxy URL via the given resolver.
     *
     * @param  callable(string):string  $urlFor  Maps a content-relative path to a proxy URL.
     * @return array{body: string, contentType: string}|null
     */
    public function manifest(Media $video, string $relativePath, callable $urlFor): ?array
    {
        if (! $video->isHlsReady()) {
            return null;
        }

        $body = $this->storage->get($this->disk(), $this->objectKey($video, $relativePath));
        if ($body === null) {
            return null;
        }

        return [
            'body' => $this->rewriteManifest($body, $relativePath, $urlFor),
            'contentType' => 'application/vnd.apple.mpegurl',
        ];
    }

    /**
     * Short-lived presigned URL for a segment/init object so the browser fetches
     * it straight from R2.
     */
    public function segmentUrl(Media $video, string $relativePath): ?string
    {
        if (! $video->isHlsReady()) {
            return null;
        }

        try {
            return $this->storage->getSignedViewUrl(
                $this->disk(),
                $this->objectKey($video, $relativePath),
                self::SEGMENT_URL_TTL_MINUTES,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Permanent-delete cleanup. Mappings are row-private and can always go; the
     * content-addressed output tree is removed only when no other media row has
     * resolved to the same content id.
     */
    public function deleteIfUnreferenced(Media $video): void
    {
        if (! $video->type->isVideo()) {
            return;
        }

        $disk = $this->disk();
        $contentId = $video->hls_content_id;
        $mappingKeys = array_values(array_unique(array_filter([
            $video->object_key,
            $video->reviewed_object_key,
        ])));

        foreach ($mappingKeys as $sourceKey) {
            if ($contentId === null) {
                $contentId = $this->lookupContentId($sourceKey);
            }

            $this->storage->deleteFile($disk, 'mappings/'.$sourceKey.'.json');
        }

        if ($contentId === null) {
            return;
        }

        $referenced = Media::withTrashed()
            ->whereKeyNot($video->id)
            ->where('hls_content_id', $contentId)
            ->exists();

        if (! $referenced) {
            $this->storage->deleteDirectory($disk, 'by-id/'.$contentId);
        }
    }

    public function isManifestPath(string $relativePath): bool
    {
        return Str::endsWith(strtolower($relativePath), '.m3u8');
    }

    /**
     * Reject anything that could escape the content tree or isn't a plausible HLS
     * artifact name.
     */
    public function isSafeRelativePath(string $relativePath): bool
    {
        if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, '\\')) {
            return false;
        }

        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return preg_match('#^[A-Za-z0-9._/-]+$#', $relativePath) === 1;
    }

    private function isRecheckDue(Media $video): bool
    {
        return $video->hls_checked_at === null
            || $video->hls_checked_at->lt(now()->subMinutes(self::RECHECK_AFTER_MINUTES));
    }

    private function lookupContentId(string $sourceKey): ?string
    {
        $raw = $this->storage->get($this->disk(), 'mappings/'.$sourceKey.'.json');
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        $contentId = is_array($decoded) ? ($decoded['contentId'] ?? null) : null;

        return is_string($contentId) && $contentId !== '' ? $contentId : null;
    }

    private function objectKey(Media $video, string $relativePath): string
    {
        return 'by-id/'.$video->hls_content_id.'/'.$relativePath;
    }

    /**
     * Rewrite a media/master playlist so every child reference points back at the
     * proxy. URI references are resolved relative to the manifest's own directory.
     *
     * @param  callable(string):string  $urlFor
     */
    private function rewriteManifest(string $body, string $manifestRelativePath, callable $urlFor): string
    {
        $baseDir = str_contains($manifestRelativePath, '/')
            ? substr($manifestRelativePath, 0, strrpos($manifestRelativePath, '/'))
            : '';

        $resolve = function (string $childUri) use ($baseDir, $urlFor): string {
            $path = $baseDir === '' ? $childUri : $baseDir.'/'.$childUri;

            return $urlFor($this->normalize($path));
        };

        $lines = preg_split('/\R/', $body) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $out[] = $line;

                continue;
            }

            if (str_starts_with($trimmed, '#')) {
                // Rewrite URI="..." attributes (EXT-X-MAP, EXT-X-KEY, EXT-X-MEDIA, …).
                $out[] = preg_replace_callback(
                    '/URI="([^"]+)"/',
                    fn (array $m): string => 'URI="'.$resolve($m[1]).'"',
                    $line,
                );

                continue;
            }

            // Bare URI line: a sub-playlist or a segment.
            $out[] = $resolve($trimmed);
        }

        return implode("\n", $out);
    }

    /**
     * Collapse "." / ".." segments in a content-relative path.
     */
    private function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }
}
