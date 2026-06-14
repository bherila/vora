<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stateless wrapper around S3-compatible (R2) filesystem disks providing
 * presigned upload URLs, signed view/download URLs, and object operations.
 * The disk is passed per call so the service can be injected and mocked.
 * Bucket names come from the disk config, never from code.
 */
class FileStorageService
{
    protected function storage(string $disk): Filesystem
    {
        return Storage::disk($disk);
    }

    protected function bucket(string $disk): string
    {
        $bucket = config("filesystems.disks.{$disk}.bucket");

        if (empty($bucket)) {
            throw new \RuntimeException("Bucket is not configured for disk [{$disk}].");
        }

        return $bucket;
    }

    /**
     * Generate a presigned URL the browser can PUT a file to directly, avoiding
     * server upload size limits. The S3/R2 adapter returns both the URL and the
     * headers (e.g. Content-Type) that were signed and which the client must
     * send on the PUT, so both are returned here.
     *
     * @return array{url: string, headers: array<string, string>}
     */
    public function getSignedUploadUrl(string $disk, string $key, string $contentType, int $ttlMinutes = 30): array
    {
        $result = $this->storage($disk)->temporaryUploadUrl(
            $key,
            now()->addMinutes($ttlMinutes),
            [
                'Bucket' => $this->bucket($disk),
                'ContentType' => $contentType,
            ],
        );

        return [
            'url' => $result['url'],
            'headers' => $result['headers'] ?? [],
        ];
    }

    /**
     * Signed URL for viewing an object inline in the browser (img/video src).
     */
    public function getSignedViewUrl(string $disk, string $key, int $ttlMinutes = 60, ?string $contentType = null): string
    {
        $options = [
            'Bucket' => $this->bucket($disk),
            'ResponseContentDisposition' => 'inline',
        ];

        if ($contentType !== null) {
            $options['ResponseContentType'] = $contentType;
        }

        return $this->storage($disk)->temporaryUrl($key, now()->addMinutes($ttlMinutes), $options);
    }

    /**
     * Signed URL that forces a download with the given filename.
     */
    public function getSignedDownloadUrl(string $disk, string $key, string $downloadFilename, int $ttlMinutes = 60): string
    {
        return $this->storage($disk)->temporaryUrl(
            $key,
            now()->addMinutes($ttlMinutes),
            [
                'Bucket' => $this->bucket($disk),
                'ResponseContentDisposition' => 'attachment; filename="'.addslashes($downloadFilename).'"',
            ],
        );
    }

    public function uploadFile(string $disk, UploadedFile $file, string $key): bool
    {
        return $this->storage($disk)->putFileAs(dirname($key), $file, basename($key)) !== false;
    }

    public function get(string $disk, string $key): ?string
    {
        $storage = $this->storage($disk);

        return $storage->exists($key) ? $storage->get($key) : null;
    }

    public function deleteFile(string $disk, string $key): bool
    {
        return $this->storage($disk)->delete($key);
    }

    public function fileExists(string $disk, string $key): bool
    {
        return $this->storage($disk)->exists($key);
    }

    public function getFileSize(string $disk, string $key): ?int
    {
        try {
            return $this->storage($disk)->size($key);
        } catch (\Exception) {
            return null;
        }
    }
}
