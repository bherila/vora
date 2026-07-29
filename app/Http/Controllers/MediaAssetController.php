<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams visitor-facing images without exposing their legacy storage keys.
 * Those keys may contain the uploader's numeric user id, so redirecting to a
 * presigned object URL would still leak the identity after the redirect.
 */
class MediaAssetController extends Controller
{
    public function __construct(private readonly FileStorageService $storage) {}

    public function show(Request $request, string $ulid, string $variant): StreamedResponse
    {
        $media = Media::query()->where('ulid', $ulid)->first();
        $this->authorizeOr404('view', $media);
        abort_unless($media instanceof Media && $media->isReady(), 404, 'Not found.');

        $viewer = $request->user();
        $isOwnerOrAdmin = $viewer instanceof User
            && ($viewer->id === $media->user_id || $viewer->isAdmin());

        if ($variant === 'thumbnail') {
            $disk = (string) config('media.thumbnail_disk');
            $key = $media->playbackThumbnailKey();
            $contentType = 'image/jpeg';
        } else {
            abort_unless($variant === 'original' && (! $media->type->isVideo() || $isOwnerOrAdmin), 404, 'Not found.');
            $disk = $media->disk;
            $key = $media->playbackObjectKey();
            $contentType = $media->mime_type;
        }

        abort_unless(is_string($key) && $key !== '', 404, 'Not found.');
        $stream = $this->storage->readStream($disk, $key);
        abort_unless(is_resource($stream), 404, 'Not found.');

        $headers = [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'inline',
            // The URL carries no viewer-specific token. Do not let a browser
            // reuse bytes after logout, account switch, or access revocation
            // without re-running MediaPolicy.
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
        $size = $this->storage->getFileSize($disk, $key);
        if ($size !== null) {
            $headers['Content-Length'] = (string) $size;
        }

        return response()->stream(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, $headers);
    }
}
