<?php

namespace App\Services\Media;

use App\Models\LibraryItem;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Closure;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipStream\CompressionMethod;
use ZipStream\Stream\CallbackStreamWrapper;
use ZipStream\ZipStream;

class BulkDownloadArchive
{
    public const MAX_FILES = 50;

    public const MAX_BYTES = 2 * 1024 * 1024 * 1024;

    public const MAX_SECONDS = 1800;

    public function __construct(private readonly BulkTransferSelection $selection, private readonly FileStorageService $storage) {}

    /** @param list<string> $ulids @return list<array<string, mixed>> */
    public function preflight(User $user, string $source, array $ulids): array
    {
        abort_unless(count($ulids) > 0 && count($ulids) <= self::MAX_FILES, 422, 'Select between 1 and 50 files.');
        $items = $this->selection->resolve($user, $source, $ulids);
        $entries = [];
        $bytes = 0;
        foreach ($items as $item) {
            $media = $item instanceof LibraryItem ? $item->media : $item;
            abort_unless($media instanceof Media && $media->isReady(), 404, 'Not found.');
            if (isset($entries[$media->id])) {
                continue;
            }
            $key = $media->playbackObjectKey();
            $size = $this->storage->getFileSize($media->disk, $key);
            abort_unless($size !== null && $size > 0, 404, 'Not found.');
            $bytes += $size;
            if ($bytes > self::MAX_BYTES) {
                throw ValidationException::withMessages(['ulids' => 'ZIP downloads are limited to 50 files and 2 GiB total. Download larger videos individually.']);
            }
            // Verify GET access to every original before returning a streamed
            // response. Keep at most one resource open and never read its body.
            $stream = $this->storage->readStream($media->disk, $key);
            abort_unless(is_resource($stream), 404, 'Not found.');
            fclose($stream);
            $entries[$media->id] = [
                'reference' => $item->ulid, 'media_id' => $media->id, 'disk' => $media->disk, 'key' => $key,
                'size' => $size, 'name' => $this->filename($user, $media), 'date' => $media->created_at?->toDateTimeImmutable(),
            ];
        }

        return array_values($entries);
    }

    /**
     * A single-pass ZIP: no temporary archive, compression buffer, or whole-file
     * read. The PSR stream caps R2 reads at 64 KiB; ZipStream/PHP also reserves
     * a fixed 16 MiB fread buffer. Directory memory is bounded by MAX_FILES.
     * The callback is injectable for bounded-read tests.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    public function write(User $user, string $source, array $entries, ?Closure $emit = null): void
    {
        $emit ??= static function (string $data): void {
            if (connection_aborted()) {
                throw new RuntimeException('Download disconnected.');
            }
            echo $data;
            if (! app()->runningUnitTests()) {
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        };
        $deadline = hrtime(true) + self::MAX_SECONDS * 1_000_000_000;
        $output = CallbackStreamWrapper::open(static function (string $data) use ($emit, $deadline): void {
            if (hrtime(true) > $deadline) {
                throw new RuntimeException('Download time limit reached.');
            }
            $emit($data);
        });
        try {
            $zip = new ZipStream(
                outputStream: $output, sendHttpHeaders: false, enableZip64: true,
                defaultEnableZeroHeader: true, defaultCompressionMethod: CompressionMethod::STORE,
            );
            foreach ($entries as $entry) {
                if (hrtime(true) > $deadline) {
                    throw new RuntimeException('Download time limit reached.');
                }
                $viewer = User::findOrFail($user->id);
                abort_unless($viewer->isActive() && $viewer->isApproved(), 404, 'Not found.');
                $item = $this->selection->resolve($viewer, $source, [$entry['reference']])->first();
                $media = $item instanceof LibraryItem ? $item->media : $item;
                abort_unless($media instanceof Media && $media->id === $entry['media_id'] && $media->disk === $entry['disk']
                    && $media->playbackObjectKey() === $entry['key']
                    && $this->storage->getFileSize($entry['disk'], $entry['key']) === $entry['size'], 404, 'Not found.');
                $resource = $this->storage->readStream($entry['disk'], $entry['key']);
                abort_unless(is_resource($resource), 404, 'Not found.');
                $metadata = stream_get_meta_data($resource);
                if (($metadata['wrapper_type'] ?? '') === 'http' || str_contains($metadata['stream_type'] ?? '', 'socket')) {
                    stream_set_timeout($resource, 30);
                }
                $stream = new BoundedDownloadStream(Utils::streamFor($resource));
                try {
                    $zip->addFileFromPsr7Stream(
                        fileName: $entry['name'], stream: $stream, exactSize: $entry['size'],
                        lastModificationDateTime: $entry['date'],
                    );
                } finally {
                    $stream->close();
                }
            }
            $zip->finish();
        } finally {
            fclose($output);
        }
    }

    private function filename(User $user, Media $media): string
    {
        $extension = $media->downloadExtension();
        if ($media->user_id !== $user->id) {
            // Shared filenames can contain names/emails intentionally excluded
            // from visitor DTOs. Do not disclose them through ZIP metadata.
            return 'media-'.$media->ulid.'.'.$extension;
        }
        $name = basename(str_replace('\\', '/', $media->original_filename));
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
        $stem = trim(pathinfo($name, PATHINFO_FILENAME), ' .');
        $stem = $stem !== '' ? Str::limit($stem, 150, '') : 'media';
        $rawExtension = pathinfo($name, PATHINFO_EXTENSION);
        $originalExtension = strtolower($rawExtension);
        $safeExtension = $originalExtension === $extension || ($extension === 'jpg' && $originalExtension === 'jpeg')
            || ($extension === 'heic' && $originalExtension === 'heif');

        // Per-media directories avoid overwriting two identically named files.
        return $media->ulid.'/'.$stem.'.'.($safeExtension ? $rawExtension : $extension);
    }
}
