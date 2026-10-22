<?php

namespace App\Services\Media;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Support\Facades\DB;

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
        if ($media->multipart_upload_id !== null) {
            $this->storage->abortMultipartUpload($media->disk, $media->object_key, $media->multipart_upload_id);
            $media->forceFill([
                'multipart_upload_id' => null,
                'multipart_part_size_bytes' => null,
                'multipart_initiated_at' => null,
            ])->save();
        }

        if (! $media->trashed()) {
            $media->delete();
        }
    }

    /**
     * Release the upload/publication reference. Independent Library references
     * retain the media row and its objects without the uploader identity. Once
     * there are no references, purge source/reviewed objects and derivatives.
     * HLS cleanup also checks other media rows sharing the encoded content id.
     */
    public function delete(Media $media): void
    {
        DB::transaction(function () use ($media): void {
            $media = Media::withTrashed()->whereKey($media->id)->lockForUpdate()->first();
            if (! $media instanceof Media) {
                return;
            }
            if ($media->libraryItems()->exists()) {
                // Remove the uploader's publication/identity reference while
                // retaining the one stored file for independent saved copies.
                $media->forceFill([
                    'user_id' => null, 'character_id' => null, 'canonical_post_id' => null,
                    'audience' => Audience::SpecificPeople, 'discoverable' => false,
                    'announce_on_approval' => false,
                ])->save();
                $media->audienceMembers()->delete();
                $this->softDelete($media);

                return;
            }

            $this->purgeObjects($media);
        });
    }

    private function purgeObjects(Media $media): void
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
