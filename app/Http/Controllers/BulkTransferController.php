<?php

namespace App\Http\Controllers;

use App\Http\Requests\Media\BulkTransferRequest;
use App\Models\LibraryItem;
use App\Models\Media;
use App\Services\FileStorageService;
use App\Services\Library\LibraryBulkSave;
use App\Services\Media\BulkDownloadArchive;
use App\Services\Media\BulkTransferSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class BulkTransferController extends Controller
{
    private function source(Request $request): string
    {
        return $request->routeIs('bulk-transfer.events.*') ? 'events' : 'library';
    }

    public function save(BulkTransferRequest $request, LibraryBulkSave $save): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['saved_count' => $save->save($request->user(), $this->source($request), $request->validated('ulids'))]]);
    }

    public function prepare(BulkTransferRequest $request, BulkDownloadArchive $archive): JsonResponse
    {
        $source = $this->source($request);
        $ulids = $request->validated('ulids');
        $entries = $archive->preflight($request->user(), $source, $ulids);
        $token = Str::random(48);
        Cache::put('bulk-download:'.$token, [
            'user_id' => $request->user()->id, 'session' => hash('sha256', $request->session()->getId()),
            'source' => $source, 'ulids' => $ulids,
        ], now()->addMinutes(5));

        return response()->json(['success' => true, 'data' => [
            'download_url' => route('bulk-transfer.download', ['token' => $token], false), 'file_count' => count($entries),
        ]]);
    }

    public function downloadItem(Request $request, string $ulid, BulkTransferSelection $selection, FileStorageService $storage): StreamedResponse
    {
        $item = $selection->resolve($request->user(), 'library', [$ulid])->first();
        $media = $item instanceof LibraryItem ? $item->media : null;
        abort_unless($media instanceof Media && $media->isReady(), 404, 'Not found.');
        $size = $storage->getFileSize($media->disk, $media->playbackObjectKey());
        abort_unless($size !== null && $size > 0, 404, 'Not found.');
        $stream = $storage->readStream($media->disk, $media->playbackObjectKey());
        abort_unless(is_resource($stream), 404, 'Not found.');

        return response()->streamDownload(static function () use ($stream): void {
            try {
                while (! feof($stream) && ! connection_aborted()) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                        break;
                    }
                    echo $chunk;
                    if (! app()->runningUnitTests()) {
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }
                }
            } catch (Throwable) {
                Log::warning('Original media download interrupted.');
            } finally {
                fclose($stream);
            }
        }, 'media-'.$media->ulid.'.'.$media->downloadExtension(), [
            'Content-Type' => $media->mime_type, 'Content-Length' => (string) $size, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'X-Accel-Buffering' => 'no',
        ]);
    }

    public function download(Request $request, string $token, BulkDownloadArchive $archive): StreamedResponse
    {
        abort_unless($request->isMethod('GET'), 405);
        $key = 'bulk-download:'.$token;
        $ticket = Cache::get($key);
        abort_unless(is_array($ticket) && $ticket['user_id'] === $request->user()->id
            && hash_equals($ticket['session'], hash('sha256', $request->session()->getId())), 404, 'Not found.');
        $lock = Cache::lock('bulk-download:user:'.$request->user()->id, 3600);
        abort_unless($lock->get(), 429, 'A ZIP download is already running. Wait for it to finish.');
        try {
            // Another request may have consumed this ticket while this request
            // was waiting to acquire the per-account lock.
            $ticket = Cache::get($key);
            abort_unless(is_array($ticket) && $ticket['user_id'] === $request->user()->id
                && hash_equals($ticket['session'], hash('sha256', $request->session()->getId())), 404, 'Not found.');
            $entries = $archive->preflight($request->user(), $ticket['source'], $ticket['ulids']);
            Cache::forget($key);
        } catch (Throwable $error) {
            $lock->release();
            throw $error;
        }
        app()->terminating(static fn () => $lock->release());

        return response()->streamDownload(function () use ($request, $archive, $ticket, $entries, $lock): void {
            try {
                $archive->write($request->user(), $ticket['source'], $entries);
            } catch (Throwable) {
                // A revocation/disconnect/storage failure after output starts
                // must truncate the ZIP, never append a rendered HTML exception
                // or continue to unauthorized entries. No keys/URLs in logs.
                Log::warning('Bulk ZIP download interrupted.');
            } finally {
                $lock->release();
            }
        }, 'selected-media.zip', [
            'Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'X-Accel-Buffering' => 'no',
        ]);
    }
}
