<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;

class MediaModerationService
{
    public function __construct(private readonly FileStorageService $storage) {}

    /** Null moderator is reserved for server-authorized automatic approval. */
    public function approve(Media $media, ?User $admin, ?string $notes = null): bool
    {
        if (! $media->isReady()) {
            return false;
        }

        // A copy prepared before review is what the reviewer saw and what the
        // transcoder already processed; publish it rather than re-copying the
        // original, which the uploader's upload URL could still overwrite.
        if (! $this->hasReviewedCopies($media) && ! $this->copyReviewedObjects($media)) {
            return false;
        }

        $media->approve($admin, $notes);

        return true;
    }

    /**
     * Copy a video awaiting review into reviewed storage without approving it,
     * so the transcoder can process it before an administrator decides. The
     * video stays visible only to its uploader and administrators.
     */
    public function prepareForReview(Media $media): bool
    {
        if (! $media->isReady() || ! $media->isPendingReview() || ! $media->type->isVideo()) {
            return false;
        }

        if ($this->hasReviewedCopies($media)) {
            return true;
        }

        if (! $this->copyReviewedObjects($media)) {
            return false;
        }

        $media->save();

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

    private function hasReviewedCopies(Media $media): bool
    {
        return $media->reviewed_object_key !== null
            && ($media->thumbnail_key === null || $media->reviewed_thumbnail_key !== null)
            && $this->storage->fileExists($media->disk, $media->reviewed_object_key);
    }

    /** Snapshot the original (and thumbnail) into reviewed keys; the caller saves. */
    private function copyReviewedObjects(Media $media): bool
    {
        if (! $this->storage->fileExists($media->disk, $media->object_key)) {
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

        return true;
    }

    private function reviewedObjectKey(Media $media): string
    {
        $prefix = trim((string) config('media.key_prefix', 'uploads'), '/');
        $reviewedPrefix = $media->type->isVideo() ? config('media.reviewed_key_prefix') : null;
        $reviewedPrefix = $reviewedPrefix ? trim((string) $reviewedPrefix, '/') : $prefix.'/reviewed';
        $extension = pathinfo($media->object_key, PATHINFO_EXTENSION) ?: 'bin';

        return $reviewedPrefix.'/'.$media->user_id.'/'.$media->ulid.'.'.$extension;
    }

    private function reviewedThumbnailKey(Media $media): string
    {
        $prefix = trim((string) config('media.key_prefix', 'uploads'), '/');

        return $prefix.'/reviewed-thumbnails/'.$media->user_id.'/'.$media->ulid.'.jpg';
    }
}
