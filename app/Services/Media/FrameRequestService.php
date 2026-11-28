<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Services\FileStorageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The app's side of the transcoder's frame worker (cmd/framegrabber; its
 * docs/INTEGRATION.md §7): requests are written to the video source disk as
 * frame-requests/<contentId>/<ms>.json, and answers appear in the HLS disk as
 * by-id/<contentId>/frames/<ms>.jpg (with <ms>_thumb.jpg) or <ms>.error.json.
 * The worker never deletes requests; this service does once it reads an answer.
 */
class FrameRequestService
{
    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    public function __construct(private readonly FileStorageService $storage) {}

    private function hlsDisk(): string
    {
        return (string) config('media.hls_disk', 'hls');
    }

    public function frameKey(string $contentId, int $ms, bool $thumbnail = false): string
    {
        return "by-id/{$contentId}/frames/{$ms}".($thumbnail ? '_thumb' : '').'.jpg';
    }

    private function errorKey(string $contentId, int $ms): string
    {
        return "by-id/{$contentId}/frames/{$ms}.error.json";
    }

    private function requestKey(string $contentId, int $ms): string
    {
        return rtrim((string) config('media.moments.frame_request_prefix', 'frame-requests/'), '/')."/{$contentId}/{$ms}.json";
    }

    /** Ask the worker for a frame, and wake it when a wake URL is configured. */
    public function request(Media $video, string $contentId, int $ms): void
    {
        $body = json_encode(['contentId' => $contentId, 'ms' => $ms, 'requestedAt' => now()->toIso8601String()], JSON_THROW_ON_ERROR);
        $this->storage->putString($video->disk, $this->requestKey($contentId, $ms), $body, 'application/json');
        $this->wake();
    }

    /** Remove a request once its answer has been read. */
    public function clearRequest(Media $video, string $contentId, int $ms): void
    {
        $this->storage->deleteFile($video->disk, $this->requestKey($contentId, $ms));
    }

    /**
     * The worker's answer for a frame: ready, failed, or still pending. An
     * error marker older than $requestedAt belongs to an earlier attempt (the
     * worker deletes it when it retries), so it counts as pending.
     */
    public function status(string $contentId, int $ms, ?\DateTimeInterface $requestedAt = null): string
    {
        if ($this->storage->fileExists($this->hlsDisk(), $this->frameKey($contentId, $ms))) {
            return self::STATUS_READY;
        }
        $failedAt = $this->storage->lastModified($this->hlsDisk(), $this->errorKey($contentId, $ms));
        if ($failedAt === null || ($requestedAt !== null && $failedAt < $requestedAt->getTimestamp())) {
            return self::STATUS_PENDING;
        }

        return self::STATUS_FAILED;
    }

    public function signedFrameUrl(string $contentId, int $ms, bool $thumbnail): string
    {
        return $this->storage->getSignedViewUrl($this->hlsDisk(), $this->frameKey($contentId, $ms, $thumbnail), 10, 'image/jpeg');
    }

    public function frameBytes(string $contentId, int $ms): ?string
    {
        return $this->storage->get($this->hlsDisk(), $this->frameKey($contentId, $ms));
    }

    private function wake(): void
    {
        $url = config('media.moments.framegrabber_wake_url');
        if (! is_string($url) || $url === '') {
            return;
        }
        try {
            $request = Http::timeout(2)->connectTimeout(2);
            $token = config('media.moments.framegrabber_wake_token');
            if (is_string($token) && $token !== '') {
                $request = $request->withToken($token);
            }
            $request->post($url);
        } catch (\Throwable $e) {
            // The worker also polls; a missed wake only delays the frame.
            Log::warning('Frame worker wake failed.', ['error' => $e->getMessage()]);
        }
    }
}
