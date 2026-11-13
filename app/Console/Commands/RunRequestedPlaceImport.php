<?php

namespace App\Console\Commands;

use App\Models\PlaceImportRun;
use App\Services\Location\PlaceImporter;
use App\Services\Location\PlaceImportOptions;
use App\Services\Location\PlaceImportRuns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Runs the next import an admin requested (or the monthly refresh queued).
 * The scheduler starts it in the background; it imports only while holding
 * the import lock and claims the request with a conditional update, so a
 * request runs at most once and never alongside another import.
 */
class RunRequestedPlaceImport extends Command
{
    protected $signature = 'places:run-requested';

    protected $description = 'Run the next GeoNames import requested from the admin page';

    public function handle(PlaceImporter $importer, PlaceImportRuns $runs): int
    {
        $runs->cleanup();
        if (! $runs->hasPending()) {
            return self::SUCCESS;
        }

        $outcome = $runs->exclusively(function () use ($runs, $importer): int {
            $run = $runs->claim();
            if (! $run instanceof PlaceImportRun) {
                return self::SUCCESS;
            }

            // A queued apply may have waited behind another import.
            if (($stale = $runs->staleApplyReason($run)) !== null) {
                $runs->fail($run, $stale);
                $this->error($stale);

                return self::FAILURE;
            }

            // A downloaded preview keeps its files, like an uploaded one, so
            // applying it imports exactly what was previewed.
            $download = $run->dry_run && $run->files_run_id === null;
            if ($download) {
                DB::table('place_import_runs')->where('id', $run->id)->update(['files_run_id' => $run->id]);
                $run->files_run_id = $run->id;
                File::ensureDirectoryExists($runs->filesDir($run->id));
            }
            $dir = $run->files_run_id !== null ? $runs->filesDir($run->files_run_id) : null;
            if (! $download && $dir !== null && ! $this->hasAllFiles($dir)) {
                $runs->fail($run, 'The GeoNames files for this run are missing. Upload them again.');
                $this->error('The GeoNames files for this run are missing.');

                return self::FAILURE;
            }

            $log = fn (string $message) => $this->line($message);
            try {
                $runs->complete($run, function () use ($importer, $runs, $run, $dir, $download, $log) {
                    if ($download && $dir !== null) {
                        $importer->download($run->dataset, $dir, $log, fn () => $runs->heartbeat($run));
                    }

                    return $importer->run(new PlaceImportOptions(
                        dataset: $run->dataset,
                        mode: in_array($run->mode, PlaceImportOptions::MODES, true) ? $run->mode : PlaceImportOptions::MODE_UPDATE,
                        rehome: $run->rehome,
                        dryRun: $run->dry_run,
                        minimumRows: (int) config('places.minimum_rows'),
                        citiesPath: $dir !== null ? $this->file($dir, ['cities.zip', 'cities.txt']) : null,
                        admin1Path: $dir !== null ? $this->file($dir, ['admin1CodesASCII.txt']) : null,
                        countriesPath: $dir !== null ? $this->file($dir, ['countryInfo.txt']) : null,
                    ), $log, fn () => $runs->heartbeat($run));
                });
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            return self::SUCCESS;
        });

        if ($outcome === false) {
            $this->line('Another import is running; the request stays queued.');

            return self::SUCCESS;
        }
        $runs->cleanup();

        return $outcome;
    }

    private function hasAllFiles(string $dir): bool
    {
        return $this->file($dir, ['cities.zip', 'cities.txt']) !== null
            && $this->file($dir, ['admin1CodesASCII.txt']) !== null
            && $this->file($dir, ['countryInfo.txt']) !== null;
    }

    /**
     * @param  list<string>  $names
     */
    private function file(string $dir, array $names): ?string
    {
        foreach ($names as $name) {
            if (is_file($dir.'/'.$name)) {
                return $dir.'/'.$name;
            }
        }

        return null;
    }
}
