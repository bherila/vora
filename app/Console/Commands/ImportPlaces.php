<?php

namespace App\Console\Commands;

use App\Services\Location\PlaceImporter;
use App\Services\Location\PlaceImportOptions;
use App\Services\Location\PlaceImportRequests;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Loads or refreshes the local GeoNames city list. See {@see PlaceImporter}.
 */
class ImportPlaces extends Command
{
    protected $signature = 'places:import
        {--dataset= : GeoNames city dump (cities500, cities1000, cities5000, cities15000); defaults to the admin setting or config}
        {--mode=update : "update", or "replace" to also prune unused regions and countries}
        {--rehome : With --mode=replace, move accounts off dropped cities to the nearest remaining one}
        {--dry-run : Report what would change without writing anything}
        {--cities= : Local path to a cities .txt or .zip instead of downloading}
        {--admin1= : Local path to admin1CodesASCII.txt instead of downloading}
        {--countries= : Local path to countryInfo.txt instead of downloading}
        {--min-rows= : Refuse to prune when fewer cities were parsed; defaults to config}
        {--no-prune : Keep rows that the new data no longer contains}';

    protected $description = 'Import or refresh the local GeoNames city list used by location pickers';

    public function handle(PlaceImporter $importer, PlaceImportRequests $requests): int
    {
        $mode = (string) $this->option('mode');
        if (! in_array($mode, PlaceImportOptions::MODES, true)) {
            $this->error("Unknown mode [{$mode}].");

            return self::FAILURE;
        }
        $dataset = (string) ($this->option('dataset') ?: $requests->dataset());
        if (! in_array($dataset, PlaceImportOptions::DATASETS, true)) {
            $this->error("Unknown dataset [{$dataset}].");

            return self::FAILURE;
        }

        $options = new PlaceImportOptions(
            dataset: $dataset,
            mode: $mode,
            rehome: (bool) $this->option('rehome'),
            dryRun: (bool) $this->option('dry-run'),
            prune: ! $this->option('no-prune'),
            minimumRows: (int) ($this->option('min-rows') ?? config('places.minimum_rows')),
            citiesPath: $this->stringOption('cities'),
            admin1Path: $this->stringOption('admin1'),
            countriesPath: $this->stringOption('countries'),
        );

        try {
            $result = $requests->track(
                [
                    'source' => 'command',
                    'dataset' => $dataset,
                    'mode' => $mode,
                    'rehome' => $options->rehome,
                    'dry_run' => $options->dryRun,
                    // Local files are not kept, so such a preview cannot be applied from the admin page.
                    'local_files' => $options->citiesPath !== null || $options->admin1Path !== null || $options->countriesPath !== null,
                ],
                fn () => $importer->run($options, fn (string $message) => $this->line($message)),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['', 'Places'], collect($result->toArray())
            ->except(['dry_run', 'mode', 'dataset'])
            ->map(fn ($value, string $key): array => [str_replace('_', ' ', $key), is_bool($value) ? ($value ? 'yes' : 'no') : $value])
            ->values()->all());
        if ($result->dryRun) {
            $this->info('Dry run: nothing was changed.');
        }

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
