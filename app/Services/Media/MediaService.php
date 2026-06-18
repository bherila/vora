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
    public function __construct(private readonly FileStorageService $storage) {}

    /**
     * Delete a media item only if no user or character still points at it as a
     * profile picture. Used when an avatar is replaced or its owner deleted, so
     * the previous object/row does not leak (profile pictures are never shared,
     * but the reference check keeps this safe if that ever changes).
     */
    public function deleteIfUnreferenced(Media $media): void
    {
        $referenced = User::query()->where('profile_picture_media_id', $media->id)->exists()
            || Character::query()->where('profile_picture_media_id', $media->id)->exists();

        if (! $referenced) {
            $this->delete($media);
        }
    }

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

        $media->delete();
    }
}
