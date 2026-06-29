<?php

namespace App\Services\Media;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Enums\MediaType;
use App\Enums\ModerationStatus;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Support\Str;

/**
 * Orchestrates direct-to-storage uploads: it creates a pending media record,
 * hands back a presigned PUT URL, and later confirms the object landed.
 */
class MediaUploadService
{
    public function __construct(private readonly FileStorageService $storage) {}

    /**
     * Map of known MIME types to a canonical file extension, used to build the
     * stored object key independent of the client-supplied filename.
     */
    private const EXTENSION_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
        'video/x-matroska' => 'mkv',
    ];

    /** Content type of every client-generated thumbnail/poster derivative. */
    private const THUMBNAIL_MIME = 'image/jpeg';

    /**
     * Create a pending media record and return it alongside a presigned upload
     * URL. When $perceptualHash is supplied (photos) it is persisted for future
     * near-duplicate detection. When $wantsThumbnail is true a second presigned
     * URL is returned for the client-generated JPEG thumbnail/poster, which is
     * stored on the dedicated thumbnail disk.
     *
     * @param  list<int>  $interestIds
     * @return array{
     *     media: Media,
     *     upload_url: string,
     *     upload_headers: array<string, string>,
     *     thumbnail_upload_url: ?string,
     *     thumbnail_upload_headers: ?array<string, string>,
     * }
     */
    public function createPendingUpload(
        User $user,
        MediaType $type,
        string $filename,
        string $mimeType,
        ?string $title,
        Audience $audience,
        array $interestIds,
        bool $wantsThumbnail = false,
        ?string $perceptualHash = null,
        MediaPurpose $purpose = MediaPurpose::Gallery,
        bool $discoverable = true,
        ?int $characterId = null,
        ?string $fileHash = null,
        ?int $expectedSizeBytes = null,
    ): array {
        $ulid = (string) Str::ulid();
        $key = $this->buildObjectKey($user, $ulid, $filename, $mimeType);
        $thumbnailKey = $wantsThumbnail ? $this->buildThumbnailKey($user, $ulid) : null;

        $media = new Media([
            'user_id' => $user->id,
            'character_id' => $characterId,
            'ulid' => $ulid,
            'type' => $type,
            'purpose' => $purpose,
            'disk' => $type->disk(),
            'object_key' => $key,
            'thumbnail_key' => $thumbnailKey,
            'original_filename' => $filename,
            'mime_type' => $mimeType,
            'perceptual_hash' => $perceptualHash,
            'file_hash' => $fileHash,
            'title' => $title,
            'upload_status' => 'pending',
            'multipart_expected_size_bytes' => $expectedSizeBytes,
            'audience' => $audience,
            'discoverable' => $discoverable,
        ]);
        $media->save();

        if ($purpose === MediaPurpose::Gallery && $interestIds !== []) {
            $media->interests()->sync($interestIds);
        }

        if ($purpose === MediaPurpose::Gallery) {
            // Remember the selection so the next upload can pre-fill it.
            $user->last_media_interest_ids = array_values($interestIds);
            $user->save();
        }

        $ttl = (int) config('media.upload_url_ttl', 30);

        $signed = $this->storage->getSignedUploadUrl($media->disk, $key, $mimeType, $ttl);

        $thumbnailSigned = $thumbnailKey !== null
            ? $this->storage->getSignedUploadUrl(
                (string) config('media.thumbnail_disk'),
                $thumbnailKey,
                self::THUMBNAIL_MIME,
                $ttl,
            )
            : null;

        return [
            'media' => $media,
            'upload_url' => $signed['url'],
            'upload_headers' => $signed['headers'],
            'thumbnail_upload_url' => $thumbnailSigned['url'] ?? null,
            'thumbnail_upload_headers' => $thumbnailSigned['headers'] ?? null,
        ];
    }

    /**
     * Confirm the client finished uploading: verify the object exists and is
     * within the size limit, record its real size, and mark the record ready —
     * (re)entering admin review. Idempotent for already-ready rows.
     *
     * The real object size is checked here (not just the client-declared size at
     * presign time) so a caller can't claim a small size and PUT a larger file.
     */
    public function completeUpload(Media $media): bool
    {
        // Already completed — don't re-run (and don't reset an existing review).
        if ($media->isReady()) {
            return true;
        }

        if (! $this->storage->fileExists($media->disk, $media->object_key)) {
            return false;
        }

        $size = $this->storage->getFileSize($media->disk, $media->object_key);

        // Reject an object that exceeds the type's limit: delete it and the row.
        // Drop the thumbnail too — once the row is gone, prune can no longer
        // discover the orphaned thumbnail key.
        if ($size !== null && $size > $media->type->maxBytes()) {
            $this->storage->deleteFile($media->disk, $media->object_key);

            if ($media->thumbnail_key !== null) {
                $this->storage->deleteFile((string) config('media.thumbnail_disk'), $media->thumbnail_key);
            }

            $media->forceDelete();

            return false;
        }

        // The thumbnail is an optional best-effort derivative on its own
        // presigned PUT, so its size is unconstrained at upload time. Keep it
        // only when the client actually landed an object within the thumbnail
        // size limit; a missing or oversized thumbnail is deleted and forgotten
        // (drop the key rather than fail the whole upload).
        if ($media->thumbnail_key !== null) {
            $thumbnailDisk = (string) config('media.thumbnail_disk');
            $thumbnailSize = $this->storage->getFileSize($thumbnailDisk, $media->thumbnail_key);
            $maxThumbnailBytes = (int) config('media.thumbnail.max_bytes');

            if ($thumbnailSize === null || ($maxThumbnailBytes > 0 && $thumbnailSize > $maxThumbnailBytes)) {
                $this->storage->deleteFile($thumbnailDisk, $media->thumbnail_key);
                $media->thumbnail_key = null;
            }
        }

        $media->size_bytes = $size;
        $media->upload_status = 'ready';
        $media->multipart_upload_id = null;
        $media->multipart_part_size_bytes = null;
        $media->multipart_expected_size_bytes = null;
        $media->multipart_max_part_number = null;
        $media->multipart_initiated_at = null;
        $media->reviewed_object_key = null;
        $media->reviewed_thumbnail_key = null;
        $media->hls_content_id = null;
        $media->hls_checked_at = null;
        // Review only begins once the content actually exists; reset any prior
        // state so an approval made before the upload landed cannot carry over.
        $media->moderation_status = ModerationStatus::Pending;
        $media->moderated_by_user_id = null;
        $media->moderated_at = null;
        $media->moderation_notes = null;
        $media->save();

        return true;
    }

    /**
     * @return array{upload_id: string, part_size_bytes: int, max_part_number: int, expires_in_minutes: int}|null
     */
    public function initMultipartUpload(Media $media): ?array
    {
        if ($media->isReady() || $media->multipart_expected_size_bytes === null) {
            return null;
        }

        if ($media->multipart_upload_id !== null) {
            $this->storage->abortMultipartUpload($media->disk, $media->object_key, $media->multipart_upload_id);
            $media->multipart_upload_id = null;
            $media->multipart_part_size_bytes = null;
            $media->multipart_max_part_number = null;
            $media->multipart_initiated_at = null;
            $media->save();
        }

        $uploadId = $this->storage->createMultipartUpload($media->disk, $media->object_key, $media->mime_type);
        $partSize = $this->multipartPartSizeBytes();
        $maxPartNumber = (int) ceil($media->multipart_expected_size_bytes / $partSize);

        if ($maxPartNumber < 1 || $maxPartNumber > (int) config('media.multipart.max_parts', 10000)) {
            $this->storage->abortMultipartUpload($media->disk, $media->object_key, $uploadId);

            return null;
        }

        $media->multipart_upload_id = $uploadId;
        $media->multipart_part_size_bytes = $partSize;
        $media->multipart_max_part_number = $maxPartNumber;
        $media->multipart_initiated_at = now();
        $media->save();

        return [
            'upload_id' => $uploadId,
            'part_size_bytes' => $partSize,
            'max_part_number' => $maxPartNumber,
            'expires_in_minutes' => (int) config('media.multipart.url_ttl', 30),
        ];
    }

    /**
     * @param  list<int>  $partNumbers
     * @return list<array{part_number: int, url: string, headers: array<string, string>}>|null
     */
    public function signedMultipartPartUrls(Media $media, string $uploadId, array $partNumbers): ?array
    {
        if ($media->multipart_upload_id !== $uploadId || $media->isReady()) {
            return null;
        }

        $maxPartNumber = $media->multipart_max_part_number;
        if ($maxPartNumber === null || collect($partNumbers)->contains(fn (int $partNumber): bool => $partNumber > $maxPartNumber)) {
            return null;
        }

        $ttl = (int) config('media.multipart.url_ttl', 30);

        return collect($partNumbers)
            ->unique()
            ->sort()
            ->map(function (int $partNumber) use ($media, $uploadId, $ttl): array {
                $signed = $this->storage->getSignedMultipartUploadPartUrl(
                    $media->disk,
                    $media->object_key,
                    $uploadId,
                    $partNumber,
                    $ttl,
                );

                return [
                    'part_number' => $partNumber,
                    'url' => $signed['url'],
                    'headers' => $signed['headers'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array{part_number: int, etag: string}>  $parts
     */
    public function completeMultipartUpload(Media $media, string $uploadId, array $parts): bool
    {
        if ($media->multipart_upload_id !== $uploadId || $media->isReady() || $parts === []) {
            return false;
        }

        $maxPartNumber = $media->multipart_max_part_number;
        if ($maxPartNumber === null || collect($parts)->contains(fn (array $part): bool => $part['part_number'] > $maxPartNumber)) {
            return false;
        }

        $this->storage->completeMultipartUpload($media->disk, $media->object_key, $uploadId, $parts);
        $media->refresh();
        $media->multipart_upload_id = null;
        $media->multipart_part_size_bytes = null;
        $media->multipart_expected_size_bytes = null;
        $media->multipart_max_part_number = null;
        $media->multipart_initiated_at = null;
        $media->save();

        return $this->completeUpload($media->refresh());
    }

    public function abortMultipartUpload(Media $media, string $uploadId): bool
    {
        if ($media->multipart_upload_id !== $uploadId) {
            return false;
        }

        try {
            $this->storage->abortMultipartUpload($media->disk, $media->object_key, $uploadId);
        } finally {
            $media->multipart_upload_id = null;
            $media->multipart_part_size_bytes = null;
            $media->multipart_expected_size_bytes = null;
            $media->multipart_max_part_number = null;
            $media->multipart_initiated_at = null;
            $media->save();
        }

        return true;
    }

    private function buildObjectKey(User $user, string $ulid, string $filename, string $mimeType): string
    {
        $prefix = trim((string) config('media.key_prefix', 'uploads'), '/');

        return $prefix.'/'.$user->id.'/'.$ulid.'.'.$this->extensionFor($filename, $mimeType);
    }

    /**
     * Key for the JPEG thumbnail/poster on the thumbnail disk. Kept under a
     * separate "thumbnails" prefix and always `.jpg` regardless of source type.
     */
    private function buildThumbnailKey(User $user, string $ulid): string
    {
        $prefix = trim((string) config('media.key_prefix', 'uploads'), '/');

        return $prefix.'/thumbnails/'.$user->id.'/'.$ulid.'.jpg';
    }

    private function extensionFor(string $filename, string $mimeType): string
    {
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';

        if ($ext !== '') {
            return $ext;
        }

        return self::EXTENSION_BY_MIME[strtolower($mimeType)] ?? 'bin';
    }

    private function multipartPartSizeBytes(): int
    {
        return max(5 * 1024 * 1024, (int) config('media.multipart.part_size_bytes', 16 * 1024 * 1024));
    }
}
