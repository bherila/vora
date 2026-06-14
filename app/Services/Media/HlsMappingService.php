<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Services\FileStorageService;

/**
 * Resolves the HLS playback location for an uploaded video by reading the
 * mapping object the external s3-hls transcoder writes into the HLS output
 * bucket: mappings/<source-key>.json, whose "hlsRoot" points at the master
 * playlist. Until that object exists, the video is still processing.
 */
class HlsMappingService
{
    public function __construct(private readonly FileStorageService $storage) {}

    /**
     * @return array{status: string, playback_url: ?string, hls_root: ?string}
     */
    public function resolve(Media $media): array
    {
        if (! $media->type->isVideo()) {
            return ['status' => 'not_applicable', 'playback_url' => null, 'hls_root' => null];
        }

        $mappingKey = 'mappings/'.$media->object_key.'.json';
        $raw = $this->storage->get((string) config('media.hls_disk', 'hls'), $mappingKey);

        if ($raw === null) {
            return ['status' => 'processing', 'playback_url' => null, 'hls_root' => null];
        }

        $mapping = json_decode($raw, true);
        $hlsRoot = is_array($mapping) ? ($mapping['hlsRoot'] ?? null) : null;

        if (! is_string($hlsRoot) || $hlsRoot === '') {
            return ['status' => 'processing', 'playback_url' => null, 'hls_root' => null];
        }

        return [
            'status' => 'ready',
            'playback_url' => $this->playbackUrl($hlsRoot),
            'hls_root' => $hlsRoot,
        ];
    }

    private function playbackUrl(string $hlsRoot): ?string
    {
        $base = config('media.hls_base_url');

        if (! is_string($base) || $base === '') {
            return null;
        }

        return rtrim($base, '/').'/'.ltrim($hlsRoot, '/');
    }
}
