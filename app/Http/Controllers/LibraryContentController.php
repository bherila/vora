<?php

namespace App\Http\Controllers;

use App\Models\LibraryItem;
use App\Models\Media;
use App\Models\MediaPlaybackAuditLog;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\Library\LibraryAccess;
use App\Services\Media\HlsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LibraryContentController extends Controller
{
    public function __construct(
        private readonly LibraryAccess $access,
        private readonly FileStorageService $storage,
        private readonly HlsService $hls,
    ) {}

    public function asset(Request $request, string $ulid, string $variant): StreamedResponse
    {
        $media = $this->media($request->user(), $ulid);
        if ($variant === 'thumbnail') {
            $disk = (string) config('media.thumbnail_disk');
            $key = $media->playbackThumbnailKey();
            $mime = 'image/jpeg';
        } else {
            abort_unless($variant === 'original' && ! $media->type->isVideo(), 404, 'Not found.');
            $disk = $media->disk;
            $key = $media->playbackObjectKey();
            $mime = $media->mime_type;
        }
        abort_unless(is_string($key) && $key !== '', 404, 'Not found.');
        $stream = $this->storage->readStream($disk, $key);
        abort_unless(is_resource($stream), 404, 'Not found.');

        return response()->stream(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime, 'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function hls(Request $request, string $ulid, string $path = 'master.m3u8'): Response|RedirectResponse
    {
        $media = $this->media($request->user(), $ulid);
        abort_unless($media->type->isVideo() && $this->hls->isSafeRelativePath($path)
            && $this->hls->ensureResolved($media), 404, 'Not found.');
        if ($this->hls->isManifestPath($path)) {
            $manifest = $this->hls->manifest($media, $path,
                fn (string $child): string => route('library.hls', ['ulid' => $ulid, 'path' => $child], false));
            abort_unless($manifest !== null, 404, 'Not found.');
            $this->audit($request, $media, $ulid, 'library_hls_manifest', $path);

            return response($manifest['body'], 200, ['Content-Type' => $manifest['contentType'], 'Cache-Control' => 'private, no-store']);
        }
        $url = $this->hls->segmentUrl($media, $path);
        abort_unless($url !== null, 404, 'Not found.');
        $this->audit($request, $media, $ulid, 'library_hls_segment_redirect', $path);

        return redirect()->away($url, 302, ['Cache-Control' => 'private, no-store']);
    }

    private function media(User $viewer, string $ulid): Media
    {
        $item = LibraryItem::query()->where('ulid', $ulid)->with(['owner', 'media', 'members', 'collections.members'])->firstOrFail();
        abort_unless($this->access->canViewItem($viewer, $item)
            && $item->media instanceof Media && $this->access->canViewMedia($viewer, $item->media), 404, 'Not found.');

        return $item->media;
    }

    private function audit(Request $request, Media $media, string $ulid, string $action, string $path): void
    {
        MediaPlaybackAuditLog::query()->create([
            'media_id' => $media->id, 'user_id' => $request->user()->id,
            'action' => $action, 'path' => $path,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            'metadata' => ['library_item_ulid' => $ulid],
        ]);
    }
}
