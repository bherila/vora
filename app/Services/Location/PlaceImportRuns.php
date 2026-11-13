<?php

namespace App\Services\Location;

use App\Models\PlaceImportRun;
use App\Models\User;
use App\Services\SettingsService;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * GeoNames import runs: the admin's dataset and monthly-refresh settings,
 * the queue of requested runs, the one import lock, and each run's outcome.
 *
 * Every state change is a conditional update on the run's row ("from the
 * state I read, to the next"), so queueing, claiming and cancelling cannot
 * lose or double-run a request. Every entry point (the command, the
 * scheduler's runner) imports only while holding {@see self::LOCK}, a short
 * lease the running import renews on each heartbeat: two imports never
 * interleave writes, and a killed import frees the lock within minutes and is
 * then shown as interrupted.
 */
class PlaceImportRuns
{
    private const DATASET = 'places_dataset';

    private const AUTO_REFRESH = 'places_auto_refresh';

    private const LOCK = 'places:import';

    /** Lease on the import lock; renewed on every heartbeat. */
    public const LEASE_SECONDS = 600;

    /** A running import with no heartbeat for this long has stopped. */
    public const STALE_SECONDS = 600;

    private ?Lock $lock = null;

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
     * Queues a run for the scheduler. A newer admin request supersedes older
     * pending ones; the scheduler's runner picks admin requests before the
     * monthly refresh (see {@see self::claim()}).
     *
     * @param  array{mode: string, rehome: bool, dry_run: bool, dataset?: string|null, files_run_id?: string|null, preview_of?: string|null}  $options
     */
    public function queue(array $options, string $source, ?User $by, ?string $id = null): PlaceImportRun
    {
        $run = PlaceImportRun::query()->create([
            'id' => $id ?? (string) Str::uuid(),
            'state' => PlaceImportRun::PENDING,
            'source' => $source,
            'dataset' => in_array($options['dataset'] ?? null, PlaceImportOptions::DATASETS, true) ? $options['dataset'] : $this->dataset(),
            'mode' => $options['mode'],
            'rehome' => $options['mode'] === PlaceImportOptions::MODE_REPLACE && $options['rehome'],
            'dry_run' => $options['dry_run'],
            'files_run_id' => $options['files_run_id'] ?? null,
            'preview_of' => $options['preview_of'] ?? null,
            'requested_by' => $by?->id,
            'requested_at' => PlaceImportRun::now(),
        ]);

        if ($source === 'admin') {
            // Only strictly older requests: two requests arriving together
            // can never supersede each other, and the runner takes the newest.
            PlaceImportRun::query()
                ->where('state', PlaceImportRun::PENDING)
                ->whereKeyNot($run->id)
                ->where('requested_at', '<', $run->requested_at?->format('Y-m-d H:i:s.u'))
                ->update(['state' => PlaceImportRun::SUPERSEDED, 'finished_at' => PlaceImportRun::now()]);
        }

        return $run;
    }

    /**
     * The monthly refresh: queued only when nothing is pending or running, so
     * it never displaces an admin's request. If one arrives concurrently,
     * {@see self::claim()} still prefers it.
     */
    public function queueScheduledRefresh(): ?PlaceImportRun
    {
        $this->reapInterrupted();
        if (PlaceImportRun::query()->whereIn('state', [PlaceImportRun::PENDING, PlaceImportRun::RUNNING])->exists()) {
            return null;
        }

        return $this->queue(['mode' => PlaceImportOptions::MODE_UPDATE, 'rehome' => false, 'dry_run' => false], 'schedule', null);
    }

    public function hasPending(): bool
    {
        return PlaceImportRun::query()->where('state', PlaceImportRun::PENDING)->exists();
    }

    /**
     * The request the runner would take next.
     */
    public function nextPending(): ?PlaceImportRun
    {
        return PlaceImportRun::query()
            ->where('state', PlaceImportRun::PENDING)
            ->orderByRaw("CASE WHEN source = 'schedule' THEN 1 ELSE 0 END")
            ->orderByDesc('requested_at')
            ->first();
    }

    /**
     * Cancels a pending run. False when it already started or ended.
     */
    public function cancel(string $id): bool
    {
        return PlaceImportRun::query()
            ->whereKey($id)
            ->where('state', PlaceImportRun::PENDING)
            ->update(['state' => PlaceImportRun::CANCELLED, 'finished_at' => PlaceImportRun::now()]) === 1;
    }

    /**
     * Takes the next pending run for this process, marking it running. Older
     * pending runs it outranks are superseded. Call while holding the lock.
     */
    public function claim(): ?PlaceImportRun
    {
        $this->reapInterrupted();
        while (($next = $this->nextPending()) instanceof PlaceImportRun) {
            $taken = PlaceImportRun::query()
                ->whereKey($next->id)
                ->where('state', PlaceImportRun::PENDING)
                ->update(['state' => PlaceImportRun::RUNNING, 'started_at' => PlaceImportRun::now(), 'heartbeat_at' => PlaceImportRun::now()]) === 1;
            if (! $taken) {
                continue;
            }
            PlaceImportRun::query()
                ->where('state', PlaceImportRun::PENDING)
                ->where('requested_at', '<=', $next->requested_at?->format('Y-m-d H:i:s.u'))
                ->update(['state' => PlaceImportRun::SUPERSEDED, 'finished_at' => PlaceImportRun::now()]);

            return $next->refresh();
        }

        return null;
    }

    /**
     * Starts a run that is not queued (the command line), marked running.
     *
     * @param  array{mode: string, rehome: bool, dry_run: bool, dataset: string}  $options
     */
    public function startDirect(array $options): PlaceImportRun
    {
        $this->reapInterrupted();

        return PlaceImportRun::query()->create([
            'id' => (string) Str::uuid(),
            'state' => PlaceImportRun::RUNNING,
            'source' => 'command',
            'dataset' => $options['dataset'],
            'mode' => $options['mode'],
            'rehome' => $options['mode'] === PlaceImportOptions::MODE_REPLACE && $options['rehome'],
            'dry_run' => $options['dry_run'],
            'requested_at' => PlaceImportRun::now(),
            'started_at' => PlaceImportRun::now(),
            'heartbeat_at' => PlaceImportRun::now(),
        ]);
    }

    /**
     * Runs $work while holding the import lock, or returns false at once if
     * another import holds it. Inside, call {@see self::heartbeat()} as work
     * progresses to renew the lease.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T|false
     */
    public function exclusively(Closure $work): mixed
    {
        $lock = Cache::lock(self::LOCK, self::LEASE_SECONDS);
        if (! $lock->get()) {
            return false;
        }
        $this->lock = $lock;

        try {
            return $work();
        } finally {
            $this->lock = null;
            $lock->release();
        }
    }

    /**
     * Renews the lease and the run's heartbeat. Fails the import if the lease
     * was lost, rather than risk writing alongside another import.
     */
    public function heartbeat(?PlaceImportRun $run): void
    {
        if ($this->lock instanceof Lock && method_exists($this->lock, 'refresh')) {
            try {
                if (! $this->lock->refresh(self::LEASE_SECONDS)) {
                    throw new RuntimeException('The import lost its lock (it took too long between steps); start it again.');
                }
            } catch (RuntimeException $e) {
                if (! str_contains($e->getMessage(), 'does not support refreshing')) {
                    throw $e;
                }
            }
        }
        if ($run instanceof PlaceImportRun) {
            PlaceImportRun::query()->whereKey($run->id)->where('state', PlaceImportRun::RUNNING)->update(['heartbeat_at' => PlaceImportRun::now()]);
        }
    }

    /**
     * Records a running run's outcome. False if the run is no longer running
     * (for example marked interrupted after a long stall).
     *
     * @param  Closure(): PlaceImportResult  $work
     */
    public function complete(PlaceImportRun $run, Closure $work): PlaceImportResult
    {
        try {
            $result = $work();
        } catch (Throwable $e) {
            PlaceImportRun::query()->whereKey($run->id)->whereIn('state', [PlaceImportRun::RUNNING, PlaceImportRun::INTERRUPTED])
                ->update(['state' => PlaceImportRun::FAILED, 'error' => Str::limit($e->getMessage(), 500), 'finished_at' => PlaceImportRun::now()]);

            throw $e;
        }

        PlaceImportRun::query()->whereKey($run->id)->whereIn('state', [PlaceImportRun::RUNNING, PlaceImportRun::INTERRUPTED])
            ->update(['state' => PlaceImportRun::SUCCEEDED, 'result' => json_encode($result->toArray()), 'error' => null, 'finished_at' => PlaceImportRun::now()]);

        return $result;
    }

    public function fail(PlaceImportRun $run, string $error): void
    {
        PlaceImportRun::query()->whereKey($run->id)->whereIn('state', [PlaceImportRun::PENDING, PlaceImportRun::RUNNING])
            ->update(['state' => PlaceImportRun::FAILED, 'error' => Str::limit($error, 500), 'finished_at' => PlaceImportRun::now()]);
    }

    /**
     * Marks running imports whose heartbeat stopped (the process was killed)
     * as interrupted, so the page stops showing them as running.
     */
    public function reapInterrupted(): int
    {
        return PlaceImportRun::query()
            ->where('state', PlaceImportRun::RUNNING)
            ->where('heartbeat_at', '<', now()->subSeconds(self::STALE_SECONDS)->format('Y-m-d H:i:s.u'))
            ->update([
                'state' => PlaceImportRun::INTERRUPTED,
                'error' => 'The import stopped without finishing (its process ended). Start it again.',
                'finished_at' => PlaceImportRun::now(),
            ]);
    }

    public function running(): ?PlaceImportRun
    {
        $this->reapInterrupted();

        return PlaceImportRun::query()->where('state', PlaceImportRun::RUNNING)->latest('started_at')->first();
    }

    /**
     * The most recent run that ended in a way worth showing.
     */
    public function lastFinished(): ?PlaceImportRun
    {
        return PlaceImportRun::query()
            ->whereIn('state', [PlaceImportRun::SUCCEEDED, PlaceImportRun::FAILED, PlaceImportRun::INTERRUPTED])
            ->latest('finished_at')
            ->first();
    }

    /**
     * A preview this page may apply: a succeeded admin dry run whose files are
     * still on disk, with no import completed since it ran (which would make
     * its counts stale). Returns the run, or a reason it cannot be applied.
     */
    public function appliablePreview(string $id): PlaceImportRun|string
    {
        $preview = PlaceImportRun::query()->find($id);
        if (! $preview instanceof PlaceImportRun
            || $preview->state !== PlaceImportRun::SUCCEEDED
            || ! $preview->dry_run
            || $preview->source !== 'admin') {
            return 'That preview cannot be applied from this page. Preview again.';
        }
        if ($this->importedSince($preview)) {
            return 'The city list changed after that preview ran. Preview again.';
        }
        if ($preview->files_run_id === null || ! is_dir($this->filesDir($preview->files_run_id))) {
            return 'The previewed files are no longer available. Preview again.';
        }

        return $preview;
    }

    /**
     * Where GeoNames files live: input files under `runs/{id}`, scratch
     * directories of in-progress imports as `work-*` (`places.storage_path`).
     */
    public static function storageRoot(): string
    {
        return rtrim((string) (config('places.storage_path') ?: storage_path('app/private/geonames')), '/');
    }

    /**
     * Whether an import completed after $preview ran, making its counts stale.
     */
    public function importedSince(PlaceImportRun $preview): bool
    {
        return PlaceImportRun::query()
            ->where('state', PlaceImportRun::SUCCEEDED)
            ->where('dry_run', false)
            ->where('finished_at', '>', $preview->finished_at?->format('Y-m-d H:i:s.u'))
            ->exists();
    }

    /**
     * Checks, under the import lock and just before it runs, that an apply
     * run's preview is still current: a queued apply may have waited behind
     * another import. Returns why it may not run, or null.
     */
    public function staleApplyReason(PlaceImportRun $run): ?string
    {
        if ($run->preview_of === null) {
            return null;
        }
        $preview = PlaceImportRun::query()->find($run->preview_of);
        if (! $preview instanceof PlaceImportRun || $this->importedSince($preview)) {
            return 'The city list changed after that preview ran. Preview again.';
        }

        return null;
    }

    public function filesDir(string $runId): string
    {
        if (preg_match('/^[0-9a-f-]{36}$/', $runId) !== 1) {
            throw new RuntimeException('Invalid run id.');
        }

        return self::storageRoot().'/runs/'.$runId;
    }

    /**
     * Decides, from the database as it is now, whether one run's files are
     * still needed: an active run owns or reads them, they belong to a
     * preview that can still be applied, or no run owns them yet and they are
     * recent (an upload whose run is being created).
     */
    private function filesStillNeeded(string $runId, bool $recent): bool
    {
        $owner = PlaceImportRun::query()->find($runId);
        if (! $owner instanceof PlaceImportRun) {
            return $recent;
        }
        if ($owner->isActive()) {
            return true;
        }
        $readByActive = PlaceImportRun::query()
            ->where('files_run_id', $runId)
            ->whereIn('state', [PlaceImportRun::PENDING, PlaceImportRun::RUNNING])
            ->exists();
        if ($readByActive) {
            return true;
        }
        $latestPreview = PlaceImportRun::query()
            ->where('source', 'admin')->where('dry_run', true)->where('state', PlaceImportRun::SUCCEEDED)
            ->latest('finished_at')->value('id');

        return $latestPreview === $runId && $this->appliablePreview($runId) instanceof PlaceImportRun;
    }

    /**
     * Deletes input files nothing needs any more: files of runs that ended,
     * unless an active run reads them or they belong to the latest admin
     * preview (which can still be applied); leftover scratch directories; and
     * directories from before run records. Recent directories are kept, so a
     * request being created right now never loses its files.
     */
    public function cleanup(): void
    {
        $this->reapInterrupted();

        $root = self::storageRoot().'/runs';
        if (is_dir($root)) {
            $cutoff = Carbon::now()->subHour()->getTimestamp();
            foreach (File::directories($root) as $dir) {
                if ($this->filesStillNeeded(basename($dir), (int) filemtime($dir) >= $cutoff)) {
                    continue;
                }
                File::deleteDirectory($dir);
            }
        }

        // Scratch directories of killed imports, and pre-run-record uploads.
        $work = self::storageRoot();
        $stale = Carbon::now()->subHours(6)->getTimestamp();
        if (is_dir($work)) {
            foreach (File::directories($work) as $dir) {
                $name = basename($dir);
                if ((str_starts_with($name, 'work-') && (int) filemtime($dir) < $stale) || $name === 'uploads') {
                    File::deleteDirectory($dir);
                }
            }
        }
    }
}
