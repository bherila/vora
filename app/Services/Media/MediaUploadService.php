<?php

namespace App\Services\Media;

use App\Enums\MediaType;
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

    /**
     * Create a pending media record and return it alongside a presigned upload URL.
     *
     * @param  list<int>  $interestIds
     * @return array{media: Media, upload_url: string}
     */
    public function createPendingUpload(
        User $user,
        MediaType $type,
        string $filename,
        string $mimeType,
        ?string $title,
        Visibility $visibility,
        array $interestIds,
    ): array {
        $ulid = (string) Str::ulid();
        $key = $this->buildObjectKey($user, $ulid, $filename, $mimeType);

        $media = new Media([
            'user_id' => $user->id,
            'ulid' => $ulid,
            'type' => $type,
            'disk' => $type->disk(),
            'object_key' => $key,
            'original_filename' => $filename,
            'mime_type' => $mimeType,
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

        $uploadUrl = $this->storage->getSignedUploadUrl(
            $media->disk,
            $key,
            $mimeType,
            (int) config('media.upload_url_ttl', 30),
        );

        return ['media' => $media, 'upload_url' => $uploadUrl];
    }

    /**
     * Confirm the client finished uploading: verify the object exists, record
     * its real size, and mark the record ready for review.
     */
    public function completeUpload(Media $media): bool
    {
        if (! $this->storage->fileExists($media->disk, $media->object_key)) {
            return false;
        }

        $media->size_bytes = $this->storage->getFileSize($media->disk, $media->object_key);
        $media->upload_status = 'ready';
        $media->save();

        return true;
    }

    private function buildObjectKey(User $user, string $ulid, string $filename, string $mimeType): string
    {
        $prefix = trim((string) config('media.key_prefix', 'uploads'), '/');

        return $prefix.'/'.$user->id.'/'.$ulid.'.'.$this->extensionFor($filename, $mimeType);
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
