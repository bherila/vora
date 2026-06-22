<?php

namespace App\Services\Media;

use App\Models\Character;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;

/**
 * High-level media operations that span storage and the database.
 */
class MediaService
{
    public function __construct(
        private readonly FileStorageService $storage,
        private readonly HlsService $hls,
        private readonly PdqImageService $pdq,
    ) {}

    /**
     * Soft-delete a media item only if no user or character still points at it
     * as a profile picture. Used when an avatar is replaced or removed: the row
     * leaves owner-facing surfaces but stays restorable until admin purge.
     */
    public function deleteIfUnreferenced(Media $media): void
    {
        $referenced = User::withTrashed()->where('profile_picture_media_id', $media->id)->exists()
            || Character::withTrashed()->where('profile_picture_media_id', $media->id)->exists();

        if (! $referenced) {
            $this->softDelete($media);
        }
    }

    /**
     * User-facing deletion: hide the row everywhere outside admin retention, but
     * leave originals, reviewed copies, thumbnails, and HLS output in place so an
     * admin can restore it without re-uploading or re-transcoding.
     */
    public function softDelete(Media $media): void
    {
        if (! $media->trashed()) {
            $media->delete();
        }
    }

    /**
     * Permanently delete a media item: remove row-private objects from their
     * buckets and force-delete the database row (pivots cascade).
     *
     * Only the source object is deleted. Transcoded HLS output is shared across
     * sources with identical content (the transcoder deduplicates by content
     * hash), so cleanup of the encoded bucket is left to s3-hls's own
     * reference-counted pass — deleting it here could break another upload.
     */
    public function delete(Media $media): void
    {
        if ($media->multipart_upload_id !== null) {
            $this->storage->abortMultipartUpload($media->disk, $media->object_key, $media->multipart_upload_id);
        }

        $this->storage->deleteFile($media->disk, $media->object_key);
        if ($media->reviewed_object_key !== null) {
            $this->storage->deleteFile($media->disk, $media->reviewed_object_key);
        }

        // The thumbnail/poster is private to this row (not content-shared like
        // HLS output), so it is safe to remove alongside the source.
        if ($media->thumbnail_key !== null) {
            $this->storage->deleteFile((string) config('media.thumbnail_disk'), $media->thumbnail_key);
        }
        if ($media->reviewed_thumbnail_key !== null) {
            $this->storage->deleteFile((string) config('media.thumbnail_disk'), $media->reviewed_thumbnail_key);
        }

        $this->hls->deleteIfUnreferenced($media);

        // The PDQ mapping is row-private (just a hash), so it goes with the source.
        $this->pdq->deleteMapping($media);

        $media->forceDelete();
    }
}
