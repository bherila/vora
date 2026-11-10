<?php

namespace App\Services\Location;

use App\Models\User;
use App\Services\SettingsService;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Admin-facing state around GeoNames imports: the chosen dataset and monthly
 * refresh, one pending import request, and the last run's outcome. Requests
 * are run by `places:run-requested` from the scheduler, never inside a web
 * request, so a long import cannot hit a request or queue-worker timeout.
 */
class PlaceImportRequests
{
    private const DATASET = 'places_dataset';

    private const AUTO_REFRESH = 'places_auto_refresh';

    private const REQUEST = 'places_import_request';

    private const STATUS = 'places_import_status';

    /** Uploaded GeoNames files, one directory per request. */
    private const UPLOAD_ROOT = 'app/private/geonames/uploads';

    public function __construct(private readonly SettingsService $settings) {}

    public function dataset(): string
    {
        $dataset = $this->settings->get(self::DATASET) ?? (string) config('places.dataset');

        return in_array($dataset, PlaceImportOptions::DATASETS, true) ? $dataset : 'cities5000';
    }

    public function autoRefresh(): bool
    {
        return $this->settings->getBool(self::AUTO_REFRESH, (bool) config('places.auto_refresh'));
    }

    public function updateSettings(string $dataset, bool $autoRefresh): void
    {
        $this->settings->set(self::DATASET, $dataset);
        $this->settings->set(self::AUTO_REFRESH, $autoRefresh);
    }

    /**
     * Queues an import for the scheduler. A newer request replaces an older
     * one that has not started.
     *
     * @param  array{mode: string, rehome: bool, dry_run: bool, upload_dir: string|null, source: string, dataset?: string}  $options
     * @return array<string, mixed>
     */
    public function request(array $options, ?User $by): array
    {
        $previous = $this->pending();
        if ($previous !== null && ($previous['upload_dir'] ?? null) !== $options['upload_dir']) {
            $this->discardUploads($previous['upload_dir'] ?? null);
        }

        $request = [
            'id' => (string) Str::uuid(),
            // Applying a preview keeps the dataset that was previewed.
            'dataset' => in_array($options['dataset'] ?? null, PlaceImportOptions::DATASETS, true) ? $options['dataset'] : $this->dataset(),
            'mode' => $options['mode'],
            'rehome' => $options['rehome'],
            'dry_run' => $options['dry_run'],
            'source' => $options['source'],
            'upload_dir' => $options['upload_dir'],
            'requested_by' => $by?->id,
            'requested_at' => now()->toIso8601String(),
        ];
        $this->settings->set(self::REQUEST, (string) json_encode($request));

        return $request;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pending(): ?array
    {
        return $this->decode($this->settings->get(self::REQUEST));
    }

    public function hasPending(): bool
    {
        return $this->pending() !== null;
    }

    public function cancel(): void
    {
        $request = $this->pending();
        $this->settings->forget(self::REQUEST);
        $this->discardUploads($request['upload_dir'] ?? null);
    }

    /**
     * Takes the pending request, once, for this process.
     *
     * @return array<string, mixed>|null
     */
    public function claim(): ?array
    {
        return $this->decode($this->settings->pull(self::REQUEST));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function status(): ?array
    {
        return $this->decode($this->settings->get(self::STATUS));
    }

    /**
     * Runs an import and records its progress and outcome for the admin page.
     *
     * @param  array<string, mixed>  $meta  what was run and why
     * @param  Closure(): PlaceImportResult  $run
     */
    public function track(array $meta, Closure $run): PlaceImportResult
    {
        $started = now();
        $this->writeStatus($meta + ['state' => 'running', 'started_at' => $started->toIso8601String()]);

        try {
            $result = $run();
        } catch (Throwable $e) {
            $this->writeStatus($meta + [
                'state' => 'failed',
                'started_at' => $started->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
                'error' => Str::limit($e->getMessage(), 500),
            ]);

            throw $e;
        }

        $this->writeStatus($meta + [
            'state' => 'succeeded',
            'started_at' => $started->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
            'result' => $result->toArray(),
        ]);

        return $result;
    }

    /**
     * A fresh directory for one request's uploaded files.
     */
    public function newUploadDir(): string
    {
        $dir = storage_path(self::UPLOAD_ROOT.'/'.Str::uuid());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * Uploaded files from a preview, kept so the admin can apply them without
     * uploading again. Only directories this class created are returned.
     */
    public function previewUploadDir(): ?string
    {
        $status = $this->status();
        $dir = $status['upload_dir'] ?? null;

        return ($status['state'] ?? null) === 'succeeded'
            && ($status['dry_run'] ?? false) === true
            && is_string($dir) && $this->ownsUploadDir($dir) && is_dir($dir)
            ? $dir : null;
    }

    /**
     * Removes upload directories nothing refers to any more.
     */
    public function pruneUploads(): void
    {
        $keep = array_filter([$this->pending()['upload_dir'] ?? null, $this->previewUploadDir()]);
        $root = storage_path(self::UPLOAD_ROOT);
        if (! is_dir($root)) {
            return;
        }
        foreach (File::directories($root) as $dir) {
            if (! in_array($dir, $keep, true) && filemtime($dir) < Carbon::now()->subHour()->getTimestamp()) {
                File::deleteDirectory($dir);
            }
        }
    }

    public function discardUploads(?string $dir): void
    {
        if (is_string($dir) && $this->ownsUploadDir($dir)) {
            File::deleteDirectory($dir);
        }
    }

    private function ownsUploadDir(string $dir): bool
    {
        $root = storage_path(self::UPLOAD_ROOT).DIRECTORY_SEPARATOR;

        return str_starts_with($dir, $root) && preg_match('/^[0-9a-f-]{36}$/', substr($dir, strlen($root))) === 1;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function writeStatus(array $status): void
    {
        $this->settings->set(self::STATUS, (string) json_encode($status));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $value = json_decode($json, true);

        return is_array($value) ? $value : null;
    }
}
