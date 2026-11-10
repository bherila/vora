<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Services\Location\PlaceDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Loads or refreshes the local GeoNames city table.
 *
 * Idempotent and independent of releases: rows are upserted by GeoNames id, so
 * re-running only applies upstream changes. After a complete import, rows the
 * new data no longer contains are removed unless an account still uses them.
 */
class ImportPlaces extends Command
{
    protected $signature = 'places:import
        {--dataset= : GeoNames city dump (cities500, cities1000, cities5000, cities15000); defaults to config}
        {--cities= : Local path to a cities .txt or .zip instead of downloading}
        {--admin1= : Local path to admin1CodesASCII.txt instead of downloading}
        {--countries= : Local path to countryInfo.txt instead of downloading}
        {--min-rows= : Refuse to prune when fewer cities were parsed; defaults to config}
        {--no-prune : Keep rows that the new data no longer contains}';

    protected $description = 'Import or refresh the local GeoNames city list used by location pickers';

    /**
     * Populated-place feature codes that are not places someone lives in today.
     */
    private const SKIPPED_FEATURE_CODES = ['PPLX', 'PPLH', 'PPLQ', 'PPLW', 'PPLCH'];

    private const CHUNK = 500;

    /** GeoNames city dumps by minimum population. */
    public const DATASETS = ['cities500', 'cities1000', 'cities5000', 'cities15000'];

    public function handle(): int
    {
        $dataset = (string) ($this->option('dataset') ?: config('places.dataset'));
        if (! in_array($dataset, self::DATASETS, true)) {
            $this->error("Unknown dataset [{$dataset}].");

            return self::FAILURE;
        }

        $workDir = storage_path('app/private/geonames/'.Str::random(12));
        File::ensureDirectoryExists($workDir);

        try {
            $countries = $this->importCountries($this->source('countries', 'countryInfo.txt', $workDir));
            $regions = $this->importRegions($this->source('admin1', 'admin1CodesASCII.txt', $workDir), $countries);
            $citiesPath = $this->source('cities', "{$dataset}.zip", $workDir);
            if (str_ends_with(strtolower($citiesPath), '.zip')) {
                $citiesPath = $this->unzip($citiesPath, "{$dataset}.txt", $workDir);
            }

            // Strictly increasing, so a row from any earlier run differs.
            $batch = max(now()->getTimestamp(), (int) Place::query()->max('import_batch') + 1);
            $count = $this->importCities($citiesPath, $countries, $regions, $batch);
            $this->info("Imported {$count} places from {$dataset}.");

            $minimum = (int) ($this->option('min-rows') ?? config('places.minimum_rows'));
            if ($this->option('no-prune')) {
                return self::SUCCESS;
            }
            if ($count < $minimum) {
                $this->warn("Parsed {$count} places, fewer than the {$minimum} minimum; skipped pruning.");

                return self::SUCCESS;
            }

            $pruned = Place::query()
                ->where('import_batch', '<', $batch)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('users')->whereColumn('users.place_id', 'places.id'))
                ->delete();
            $this->info("Pruned {$pruned} places no longer in the dataset.");

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * A local path given as an option, or the file downloaded from GeoNames.
     */
    private function source(string $option, string $remoteName, string $workDir): string
    {
        $local = $this->option($option);
        if (is_string($local) && $local !== '') {
            if (! is_file($local)) {
                throw new RuntimeException("File not found: {$local}");
            }

            return $local;
        }

        $url = rtrim((string) config('places.base_url'), '/').'/'.$remoteName;
        $target = $workDir.'/'.$remoteName;
        $this->line("Downloading {$url}");
        $response = Http::timeout(300)->withOptions(['sink' => $target])->get($url);
        if (! $response->successful()) {
            throw new RuntimeException("Download failed ({$response->status()}): {$url}");
        }

        return $target;
    }

    private function unzip(string $zipPath, string $entry, string $workDir): string
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Could not open {$zipPath}");
        }
        try {
            if ($zip->locateName($entry) === false || ! $zip->extractTo($workDir, $entry)) {
                throw new RuntimeException("{$entry} not found in {$zipPath}");
            }
        } finally {
            $zip->close();
        }

        return $workDir.'/'.$entry;
    }

    /**
     * Upserts countries and returns the imported ISO codes.
     *
     * @return array<string, true>
     */
    private function importCountries(string $path): array
    {
        $rows = [];
        foreach ($this->lines($path) as $c) {
            if (isset($c[0], $c[4]) && preg_match('/^[A-Z]{2}$/', $c[0]) === 1 && $c[4] !== '') {
                $rows[$c[0]] = [
                    'code' => $c[0],
                    'name' => Str::limit($c[4], 100, ''),
                    'search_name' => Str::limit(PlaceDirectory::searchKey($c[4]), 100, ''),
                ];
            }
        }
        foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
            DB::table('place_countries')->upsert($chunk, ['code'], ['name', 'search_name']);
        }

        return array_fill_keys(array_keys($rows), true);
    }

    /**
     * Adds new first-level regions, renames changed ones, and returns their
     * ids by GeoNames key.
     *
     * @param  array<string, true>  $countries
     * @return array<string, int> "US.OR" => region id
     */
    private function importRegions(string $path, array $countries): array
    {
        $rows = [];
        foreach ($this->lines($path) as $c) {
            // "US.OR", name, ascii name, geonameid
            if (! isset($c[0], $c[1]) || ! str_contains($c[0], '.')) {
                continue;
            }
            [$country, $code] = explode('.', $c[0], 2);
            if (! isset($countries[$country]) || $code === '' || strlen($code) > 20) {
                continue;
            }
            $ascii = ($c[2] ?? '') !== '' ? $c[2] : $c[1];
            $rows[$country.'.'.$code] = [
                'country_code' => $country,
                'code' => $code,
                'name' => Str::limit($c[1], 200, ''),
                'search_name' => Str::limit(PlaceDirectory::searchKey($ascii), 200, ''),
            ];
        }
        // Only new regions are inserted, and changed ones updated in place by
        // id. An upsert would spend an AUTO_INCREMENT value per existing row
        // on MariaDB/MySQL and exhaust the SMALLINT key after a few dozen
        // refreshes.
        $existing = DB::table('place_regions')->get(['id', 'country_code', 'code', 'name', 'search_name'])
            ->keyBy(fn (object $r): string => $r->country_code.'.'.$r->code);
        $inserts = [];
        foreach ($rows as $key => $row) {
            $current = $existing->get($key);
            if ($current === null) {
                $inserts[] = $row;
            } elseif ($current->name !== $row['name'] || $current->search_name !== $row['search_name']) {
                DB::table('place_regions')->where('id', $current->id)
                    ->update(['name' => $row['name'], 'search_name' => $row['search_name']]);
            }
        }
        foreach (array_chunk($inserts, self::CHUNK) as $chunk) {
            DB::table('place_regions')->insert($chunk);
        }

        return DB::table('place_regions')->get(['id', 'country_code', 'code'])
            ->mapWithKeys(fn (object $r): array => [$r->country_code.'.'.$r->code => (int) $r->id])
            ->all();
    }

    /**
     * @param  array<string, true>  $countries
     * @param  array<string, int>  $regions
     */
    private function importCities(string $path, array $countries, array $regions, int $batch): int
    {
        $rows = [];
        $count = 0;
        $scale = Place::COORDINATE_SCALE;
        foreach ($this->lines($path) as $c) {
            // geonameid, name, asciiname, alternatenames, lat, lng, class, code,
            // country, cc2, admin1, admin2, admin3, admin4, population, ...
            if (count($c) < 15 || ! ctype_digit($c[0]) || $c[6] !== 'P' || in_array($c[7], self::SKIPPED_FEATURE_CODES, true)) {
                continue;
            }
            $country = $c[8];
            if (! isset($countries[$country]) || ! is_numeric($c[4]) || ! is_numeric($c[5])) {
                continue;
            }

            $rows[] = [
                'id' => (int) $c[0],
                'name' => Str::limit($c[1], 200, ''),
                'search_name' => Str::limit(PlaceDirectory::searchKey($c[2] !== '' ? $c[2] : $c[1]), 200, ''),
                'region_id' => $regions[$country.'.'.$c[10]] ?? null,
                'country_code' => $country,
                'lat_e4' => (int) round((float) $c[4] * $scale),
                'lng_e4' => (int) round((float) $c[5] * $scale),
                'population' => ctype_digit($c[14]) ? min((int) $c[14], 4294967295) : 0,
                'import_batch' => $batch,
            ];
            $count++;

            if (count($rows) >= self::CHUNK) {
                $this->upsert($rows);
                $rows = [];
            }
        }
        $this->upsert($rows);

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::table('places')->upsert(
            $rows,
            ['id'],
            ['name', 'search_name', 'region_id', 'country_code', 'lat_e4', 'lng_e4', 'population', 'import_batch'],
        );
    }

    /**
     * Tab-separated rows of a GeoNames text file, skipping comments.
     *
     * @return \Generator<int, list<string>>
     */
    private function lines(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Could not read {$path}");
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                yield explode("\t", $line);
            }
        } finally {
            fclose($handle);
        }
    }
}
