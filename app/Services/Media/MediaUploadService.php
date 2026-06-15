<?php

namespace App\Services\Media;

use App\Enums\MediaType;
use App\Enums\ModerationStatus;
use App\Enums\Visibility;
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
        Visibility $visibility,
        array $interestIds,
        bool $wantsThumbnail = false,
        ?string $perceptualHash = null,
    ): array {
        $ulid = (string) Str::ulid();
        $key = $this->buildObjectKey($user, $ulid, $filename, $mimeType);
        $thumbnailKey = $wantsThumbnail ? $this->buildThumbnailKey($user, $ulid) : null;

        $media = new Media([
            'user_id' => $user->id,
            'ulid' => $ulid,
            'type' => $type,
            'disk' => $type->disk(),
            'object_key' => $key,
            'thumbnail_key' => $thumbnailKey,
            'original_filename' => $filename,
            'mime_type' => $mimeType,
            'perceptual_hash' => $perceptualHash,
            'title' => $title,
            'upload_status' => 'pending',
            'visibility' => $visibility,
        ]);
        $media->save();

        if ($interestIds !== []) {
            $media->interests()->sync($interestIds);
        }

        // Remember the selection so the next upload can pre-fill it.
        $user->last_media_interest_ids = array_values($interestIds);
        $user->save();

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
        if ($size !== null && $size > $media->type->maxBytes()) {
            $this->storage->deleteFile($media->disk, $media->object_key);
            $media->delete();

            return false;
        }

        // The thumbnail is an optional best-effort derivative: if the client
        // never managed to PUT it, drop the key rather than fail the upload.
        if ($media->thumbnail_key !== null
            && ! $this->storage->fileExists((string) config('media.thumbnail_disk'), $media->thumbnail_key)) {
            $media->thumbnail_key = null;
        }

        $media->size_bytes = $size;
        $media->upload_status = 'ready';
        // Review only begins once the content actually exists; reset any prior
        // state so an approval made before the upload landed cannot carry over.
        $media->moderation_status = ModerationStatus::Pending;
        $media->moderated_by_user_id = null;
        $media->moderated_at = null;
        $media->moderation_notes = null;
        $media->save();

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
}
