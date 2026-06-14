<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Services\FileStorageService;

/**
 * High-level media operations that span storage and the database.
 */
class MediaService
{
    public function __construct(private readonly FileStorageService $storage) {}

    /**
     * Delete a media item: remove the source object from its bucket and the
     * database row (the media_interests pivot cascades).
     *
     * Only the source object is deleted. Transcoded HLS output is shared across
     * sources with identical content (the transcoder deduplicates by content
     * hash), so cleanup of the encoded bucket is left to s3-hls's own
     * reference-counted pass — deleting it here could break another upload.
     */
    public function delete(Media $media): void
    {
        $this->storage->deleteFile($media->disk, $media->object_key);

        $media->delete();
    }
}
