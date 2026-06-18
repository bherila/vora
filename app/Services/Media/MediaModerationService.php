<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;

class MediaModerationService
{
    public function __construct(private readonly FileStorageService $storage) {}

    public function approve(Media $media, User $admin, ?string $notes = null): bool
    {
        if (! $media->isReady() || ! $this->storage->fileExists($media->disk, $media->object_key)) {
            return false;
        }

        $reviewedObjectKey = $this->reviewedObjectKey($media);
        $oldReviewedObjectKey = $media->reviewed_object_key;
        $oldReviewedThumbnailKey = $media->reviewed_thumbnail_key;
        if (! $this->storage->copyFile($media->disk, $media->object_key, $media->disk, $reviewedObjectKey, $media->mime_type)) {
            return false;
        }

        $thumbnailDisk = (string) config('media.thumbnail_disk');
        $reviewedThumbnailKey = null;
        if ($media->thumbnail_key !== null) {
            $reviewedThumbnailKey = $this->reviewedThumbnailKey($media);
            if (! $this->storage->copyFile($thumbnailDisk, $media->thumbnail_key, $thumbnailDisk, $reviewedThumbnailKey, 'image/jpeg')) {
                $this->storage->deleteFile($media->disk, $reviewedObjectKey);

                return false;
            }
        }

        if ($oldReviewedObjectKey !== null && $oldReviewedObjectKey !== $reviewedObjectKey) {
            $this->storage->deleteFile($media->disk, $oldReviewedObjectKey);
        }
        if ($oldReviewedThumbnailKey !== null && $oldReviewedThumbnailKey !== $reviewedThumbnailKey) {
            $this->storage->deleteFile($thumbnailDisk, $oldReviewedThumbnailKey);
        }

        $media->reviewed_object_key = $reviewedObjectKey;
        $media->reviewed_thumbnail_key = $reviewedThumbnailKey;
        $media->hls_content_id = null;
        $media->hls_checked_at = null;
        $media->approve($admin, $notes);

        return true;
    }

    public function reject(Media $media, User $admin, ?string $notes = null): void
    {
        $this->deleteReviewedCopies($media);

        $media->reviewed_object_key = null;
        $media->reviewed_thumbnail_key = null;
        $media->hls_content_id = null;
        $media->hls_checked_at = null;
        $media->reject($admin, $notes);
    }

    public function deleteReviewedCopies(Media $media): void
    {
        if ($media->reviewed_object_key !== null) {
            $this->storage->deleteFile($media->disk, $media->reviewed_object_key);
        }

        if ($media->reviewed_thumbnail_key !== null) {
            $this->storage->deleteFile((string) config('media.thumbnail_disk'), $media->reviewed_thumbnail_key);
        }
    }

    private function reviewedObjectKey(Media $media): string
    {
        $prefix = trim((string) config('media.key_prefix', 'uploads'), '/');
        $extension = pathinfo($media->object_key, PATHINFO_EXTENSION) ?: 'bin';

        return $prefix.'/reviewed/'.$media->user_id.'/'.$media->ulid.'.'.$extension;
    }

    private function reviewedThumbnailKey(Media $media): string
    {
        $prefix = trim((string) config('media.key_prefix', 'uploads'), '/');

        return $prefix.'/reviewed-thumbnails/'.$media->user_id.'/'.$media->ulid.'.jpg';
    }
}
