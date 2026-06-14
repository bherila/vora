<?php

namespace App\Services;

use Aws\S3\S3Client;
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

    /**
     * Begin a multipart upload and return its upload id. The browser then PUTs
     * each part to a presigned URL (see presignUploadPart) and the server
     * finalises with completeMultipartUpload.
     */
    public function createMultipartUpload(string $disk, string $key, string $contentType): string
    {
        $result = $this->client($disk)->createMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $key,
            'ContentType' => $contentType,
        ]);

        return (string) $result['UploadId'];
    }

    /**
     * Presigned URL the browser PUTs a single part to. Part numbers are 1-based.
     */
    public function presignUploadPart(string $disk, string $key, string $uploadId, int $partNumber, int $ttlMinutes = 60): string
    {
        $client = $this->client($disk);
        $command = $client->getCommand('UploadPart', [
            'Bucket' => $this->bucket($disk),
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);

        return (string) $client->createPresignedRequest($command, now()->addMinutes($ttlMinutes))->getUri();
    }

    /**
     * Finalise a multipart upload from the per-part ETags the browser collected.
     *
     * @param  list<array{PartNumber: int, ETag: string}>  $parts
     */
    public function completeMultipartUpload(string $disk, string $key, string $uploadId, array $parts): bool
    {
        $this->client($disk)->completeMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $parts],
        ]);

        return true;
    }

    /**
     * Abort an in-flight multipart upload, discarding any uploaded parts.
     */
    public function abortMultipartUpload(string $disk, string $key, string $uploadId): bool
    {
        $this->client($disk)->abortMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $key,
            'UploadId' => $uploadId,
        ]);

        return true;
    }

    /**
     * The underlying S3 client for the disk (used for multipart, which Flysystem
     * does not expose directly).
     */
    protected function client(string $disk): S3Client
    {
        $adapter = $this->storage($disk);

        // @phpstan-ignore-next-line getClient() is provided by the S3 adapter.
        return $adapter->getClient();
    }
}
