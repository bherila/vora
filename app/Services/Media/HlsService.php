<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Services\FileStorageService;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Re-read metadata.json this often while it lacks a frame rate: the
     * transcoder's scene backfill can add one to older outputs.
     */
    private const METADATA_RECHECK_HOURS = 24;

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
     * Copy duration and frame rate from the transcoder's metadata.json onto the
     * row, once per ready video (and again daily while the frame rate is
     * unknown). Missing or malformed metadata leaves the columns null.
     */
    public function ensureVideoMetadata(Media $video): void
    {
        if (! $this->ensureResolved($video)) {
            return;
        }
        $checkedAt = $video->video_metadata_checked_at;
        if ($checkedAt !== null && ($video->video_frame_rate !== null
            || $checkedAt->gt(now()->subHours(self::METADATA_RECHECK_HOURS)))) {
            return;
        }

        $decoded = json_decode((string) $this->storage->get($this->disk(), $this->objectKey($video, 'metadata.json')), true);
        $source = is_array($decoded) && is_array($decoded['source'] ?? null) ? $decoded['source'] : [];

        $duration = $source['durationSeconds'] ?? null;
        if ((is_int($duration) || is_float($duration)) && $duration > 0 && $duration < 4_000_000) {
            $video->video_duration_ms = (int) round($duration * 1000);
        }
        $frameRate = $source['frameRate'] ?? null;
        if (is_string($frameRate) && preg_match('/^[1-9]\d{0,6}\/[1-9]\d{0,6}$/', $frameRate) === 1) {
            $video->video_frame_rate = $frameRate;
        }
        $video->video_metadata_checked_at = now();
        $video->saveQuietly();
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

    public function isManifestPath(string $relativePath): bool
    {
        return Str::endsWith(strtolower($relativePath), '.m3u8');
    }

    /**
     * Whether a content-relative path is something a player fetches:
     * playlists, CMAF segments, init segments, or legacy TS segments. The
     * content directory also holds metadata.json, scene analyses and
     * requested frames, which have their own access rules and are never
     * served through the playback proxy.
     */
    public function isPlayerAssetPath(string $relativePath): bool
    {
        return preg_match('/\.(m3u8|m4s|mp4|ts)$/i', $relativePath) === 1;
    }

    /**
     * The transcoder's scene analysis for a ready video (scenes.json), or
     * null when it has none yet or it is malformed. Content-addressed, so it
     * is cached per content id; "none yet" is cached briefly because a
     * backfill can add one.
     *
     * @return array{durationMs: ?int, cuts: list<array{ms: int, score: float, thumbnail: ?string}>, blackSegments: list<array{startMs: int, endMs: int, thumbnail: ?string}>}|null
     */
    public function sceneAnalysis(Media $video): ?array
    {
        if (! $this->ensureResolved($video)) {
            return null;
        }
        $cacheKey = 'hls-scene-analysis:'.$video->hls_content_id;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached['analysis'];
        }

        $analysis = $this->readSceneAnalysis($video);
        Cache::put($cacheKey, ['analysis' => $analysis], $analysis === null ? now()->addMinutes(5) : now()->addHours(6));

        return $analysis;
    }

    /**
     * @return array{durationMs: ?int, cuts: list<array{ms: int, score: float, thumbnail: ?string}>, blackSegments: list<array{startMs: int, endMs: int, thumbnail: ?string}>}|null
     */
    private function readSceneAnalysis(Media $video): ?array
    {
        $metadata = json_decode((string) $this->storage->get($this->disk(), $this->objectKey($video, 'metadata.json')), true);
        $key = is_array($metadata) ? ($metadata['analysis']['scenes']['key'] ?? null) : null;
        if (! is_string($key) || ! $this->isSafeRelativePath($key) || ! str_ends_with($key, '.json')) {
            return null;
        }
        $decoded = json_decode((string) $this->storage->get($this->disk(), $this->objectKey($video, $key)), true);
        if (! is_array($decoded) || ! is_array($decoded['cuts'] ?? null) || ! is_array($decoded['blackSegments'] ?? null)) {
            return null;
        }

        $thumbnail = fn (mixed $value): ?string => is_string($value) && preg_match('#^scenes/\d{1,9}\.jpg$#', $value) === 1 ? $value : null;
        $cuts = [];
        foreach (array_slice($decoded['cuts'], 0, 5000) as $cut) {
            if (is_array($cut) && is_int($cut['ms'] ?? null) && $cut['ms'] >= 0 && is_numeric($cut['score'] ?? null)) {
                $cuts[] = ['ms' => $cut['ms'], 'score' => (float) $cut['score'], 'thumbnail' => $thumbnail($cut['thumbnail'] ?? null)];
            }
        }
        $blacks = [];
        foreach (array_slice($decoded['blackSegments'], 0, 5000) as $black) {
            if (is_array($black) && is_int($black['startMs'] ?? null) && is_int($black['endMs'] ?? null) && $black['endMs'] > $black['startMs']) {
                $blacks[] = ['startMs' => $black['startMs'], 'endMs' => $black['endMs'], 'thumbnail' => $thumbnail($black['thumbnail'] ?? null)];
            }
        }
        $duration = $decoded['durationMs'] ?? null;

        return ['durationMs' => is_int($duration) && $duration > 0 ? $duration : null, 'cuts' => $cuts, 'blackSegments' => $blacks];
    }

    /**
     * Short-lived signed URL for one of the analysis's thumbnails.
     */
    public function sceneThumbnailUrl(Media $video, string $thumbnail): ?string
    {
        if (! $video->isHlsReady() || preg_match('#^scenes/\d{1,9}\.jpg$#', $thumbnail) !== 1) {
            return null;
        }

        return $this->storage->getSignedViewUrl($this->disk(), $this->objectKey($video, $thumbnail), 10, 'image/jpeg');
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
