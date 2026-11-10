<?php

namespace App\Console\Commands;

use App\Services\Location\PlaceImporter;
use App\Services\Location\PlaceImportOptions;
use App\Services\Location\PlaceImportRequests;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs the import an admin requested (or the monthly refresh queued). The
 * scheduler starts it in the background; claiming the request makes sure
 * only one process runs it.
 */
class RunRequestedPlaceImport extends Command
{
    protected $signature = 'places:run-requested';

    protected $description = 'Run a pending GeoNames import requested from the admin page';

    public function handle(PlaceImporter $importer, PlaceImportRequests $requests): int
    {
        $requests->pruneUploads();
        $request = $requests->claim();
        if ($request === null) {
            return self::SUCCESS;
        }

        $dir = is_string($request['upload_dir'] ?? null) ? $request['upload_dir'] : null;
        $dryRun = (bool) ($request['dry_run'] ?? false);
        $dataset = (string) ($request['dataset'] ?? $requests->dataset());
        // A downloaded preview keeps its files, like an uploaded one, so that
        // applying it imports exactly what was previewed even if GeoNames
        // publishes new data in between.
        $download = $dryRun && $dir === null;
        if ($download) {
            $dir = $requests->newUploadDir();
        } elseif ($dir !== null && ! $this->hasAllFiles($dir)) {
            $requests->discardUploads($dir);
            $this->error('The uploaded GeoNames files are missing.');

            return self::FAILURE;
        }

        $mode = in_array($request['mode'] ?? null, PlaceImportOptions::MODES, true) ? $request['mode'] : PlaceImportOptions::MODE_UPDATE;
        $rehome = (bool) ($request['rehome'] ?? false);
        $meta = [
            'request_id' => $request['id'] ?? null,
            'source' => $request['source'] ?? 'admin',
            'dataset' => $dataset,
            'mode' => $mode,
            'rehome' => $rehome,
            'dry_run' => $dryRun,
            'requested_by' => $request['requested_by'] ?? null,
            // A preview's files are kept so the admin can apply them next.
            'upload_dir' => $dryRun ? $dir : null,
        ];
        $log = fn (string $message) => $this->line($message);

        try {
            $requests->track($meta, function () use ($importer, $download, $dataset, $dir, $mode, $rehome, $dryRun, $log) {
                if ($download && $dir !== null) {
                    $importer->download($dataset, $dir, $log);
                }

                return $importer->run(new PlaceImportOptions(
                    dataset: $dataset,
                    mode: $mode,
                    rehome: $rehome,
                    dryRun: $dryRun,
                    minimumRows: (int) config('places.minimum_rows'),
                    citiesPath: $dir !== null ? $this->uploaded($dir, ['cities.zip', 'cities.txt']) : null,
                    admin1Path: $dir !== null ? $this->uploaded($dir, ['admin1CodesASCII.txt']) : null,
                    countriesPath: $dir !== null ? $this->uploaded($dir, ['countryInfo.txt']) : null,
                ), $log);
            });
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $requests->discardUploads($dir);

            return self::FAILURE;
        }

        if (! $dryRun) {
            $requests->discardUploads($dir);
        }

        return self::SUCCESS;
    }

    private function hasAllFiles(string $dir): bool
    {
        return $this->uploaded($dir, ['cities.zip', 'cities.txt']) !== null
            && $this->uploaded($dir, ['admin1CodesASCII.txt']) !== null
            && $this->uploaded($dir, ['countryInfo.txt']) !== null;
    }

    /**
     * @param  list<string>  $names
     */
    private function uploaded(string $dir, array $names): ?string
    {
        foreach ($names as $name) {
            if (is_file($dir.'/'.$name)) {
                return $dir.'/'.$name;
            }
        }

        return null;
    }
}
