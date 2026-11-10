<?php

namespace App\Services\Location;

use App\Models\Place;
use App\Services\SettingsService;
use Closure;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Loads or refreshes the local GeoNames city tables. Shared by `places:import`
 * and the admin page.
 *
 * Idempotent and independent of releases: cities are upserted by GeoNames id
 * in multi-row statements. After a complete import, cities the new data no
 * longer contains are removed unless an account uses them; such cities stay
 * for their owners but drop out of search (see {@see PlaceDirectory}).
 */
class PlaceImporter
{
    /** The latest complete import's batch; search shows cities from it onward. */
    public const CURRENT_BATCH_SETTING = 'places_current_batch';

    /** Populated-place feature codes that are not places someone lives in today. */
    private const SKIPPED_FEATURE_CODES = ['PPLX', 'PPLH', 'PPLQ', 'PPLW', 'PPLCH'];

    /** Rows per multi-row INSERT/UPSERT: ~9,000 bindings for cities. */
    private const CHUNK = 1000;

    /** Ids per IN (...) list when counting affected accounts. */
    private const ID_CHUNK = 1000;

    public function __construct(
        private readonly PlaceDirectory $directory,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  (Closure(string): void)|null  $log
     */
    public function run(PlaceImportOptions $options, ?Closure $log = null): PlaceImportResult
    {
        if (! in_array($options->dataset, PlaceImportOptions::DATASETS, true)) {
            throw new RuntimeException("Unknown dataset [{$options->dataset}].");
        }
        $log ??= static function (string $message): void {};

        $workDir = storage_path('app/private/geonames/work-'.Str::random(12));
        File::ensureDirectoryExists($workDir);

        try {
            $countriesPath = $this->source($options->countriesPath, 'countryInfo.txt', $workDir, $log);
            $admin1Path = $this->source($options->admin1Path, 'admin1CodesASCII.txt', $workDir, $log);
            $citiesPath = $this->source($options->citiesPath, "{$options->dataset}.zip", $workDir, $log);
            if (str_ends_with(strtolower($citiesPath), '.zip')) {
                $citiesPath = $this->unzip($citiesPath, "{$options->dataset}.txt", $workDir);
            }

            $countries = $this->parseCountries($countriesPath);
            $regionRows = $this->parseRegions($admin1Path, $countries);

            $existing = Place::query()->pluck('id')->map(fn ($id): int => (int) $id)->flip()->all();
            $inUse = DB::table('users')->whereNotNull('place_id')->distinct()->pluck('place_id')->map(fn ($id): int => (int) $id)->flip()->all();

            if ($options->dryRun) {
                $seen = [];
                foreach ($this->cityRows($citiesPath, $countries, [], 0) as $row) {
                    $seen[$row['id']] = true;
                }

                return $this->result($options, $seen, $existing, $inUse, removed: null, rehomed: 0, regionsRemoved: 0, countriesRemoved: 0, pruneSkipped: count($seen) < $options->minimumRows);
            }

            $this->writeCountries($countries);
            $regions = $this->writeRegions($regionRows);

            // Strictly increasing, so a row from any earlier run differs.
            $batch = max(now()->getTimestamp(), (int) Place::query()->max('import_batch') + 1);
            $seen = $this->writeCities($citiesPath, $countries, $regions, $batch);
            $log('Imported '.count($seen)." places from {$options->dataset}.");

            if (! $options->prune || count($seen) < $options->minimumRows) {
                if ($options->prune) {
                    $log('Parsed '.count($seen)." places, fewer than the {$options->minimumRows} minimum; skipped pruning.");
                }

                return $this->result($options, $seen, $existing, $inUse, removed: 0, rehomed: 0, regionsRemoved: 0, countriesRemoved: 0, pruneSkipped: true);
            }

            // A complete import: search now shows only this import's cities.
            $this->settings->set(self::CURRENT_BATCH_SETTING, $batch);

            $removed = Place::query()
                ->where('import_batch', '<', $batch)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('users')->whereColumn('users.place_id', 'places.id'))
                ->delete();
            $log("Pruned {$removed} places no longer in the dataset.");

            $rehomed = 0;
            $regionsRemoved = 0;
            $countriesRemoved = 0;
            if ($options->replaces()) {
                if ($options->rehome) {
                    [$rehomed, $rehomedPlaces] = $this->rehome($batch);
                    $removed += $rehomedPlaces;
                    $log("Moved {$rehomed} accounts to the nearest city in the new data.");
                }
                $regionsRemoved = DB::table('place_regions')
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('places')->whereColumn('places.region_id', 'place_regions.id'))
                    ->delete();
                $countriesRemoved = DB::table('place_countries')
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('places')->whereColumn('places.country_code', 'place_countries.code'))
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('place_regions')->whereColumn('place_regions.country_code', 'place_countries.code'))
                    ->delete();
                $log("Removed {$regionsRemoved} unused regions and {$countriesRemoved} unused countries.");
            }

            return $this->result($options, $seen, $existing, $inUse, $removed, $rehomed, $regionsRemoved, $countriesRemoved, pruneSkipped: false);
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * Moves accounts off cities this import dropped, to the nearest city it
     * kept, then deletes the dropped cities. Returns [accounts, places].
     *
     * @return array{int, int}
     */
    private function rehome(int $batch): array
    {
        $accounts = 0;
        $places = 0;
        $stale = Place::query()->where('import_batch', '<', $batch)->get();
        foreach ($stale as $place) {
            $target = $this->directory->nearest($place->latitude, $place->longitude);
            if (! $target instanceof Place || $target->id === $place->id) {
                continue;
            }
            $accounts += DB::table('users')->where('place_id', $place->id)->update(['place_id' => $target->id]);
            $places += $place->delete() ? 1 : 0;
        }

        return [$accounts, $places];
    }

    /**
     * @param  array<int, true>  $seen
     * @param  array<int, true>  $existing
     * @param  array<int, true>  $inUse
     */
    private function result(
        PlaceImportOptions $options,
        array $seen,
        array $existing,
        array $inUse,
        ?int $removed,
        int $rehomed,
        int $regionsRemoved,
        int $countriesRemoved,
        bool $pruneSkipped,
    ): PlaceImportResult {
        $dropped = array_diff_key($existing, $seen);
        $droppedInUse = array_intersect_key($dropped, $inUse);
        $added = count(array_diff_key($seen, $existing));
        $rehoming = $options->replaces() && $options->rehome && ! $pruneSkipped;

        $accountsAffected = 0;
        foreach (array_chunk(array_keys($droppedInUse), self::ID_CHUNK) as $ids) {
            $accountsAffected += DB::table('users')->whereIn('place_id', $ids)->count();
        }

        return new PlaceImportResult(
            dryRun: $options->dryRun,
            mode: $options->mode,
            dataset: $options->dataset,
            parsed: count($seen),
            added: $added,
            updated: count($seen) - $added,
            removed: $removed ?? ($pruneSkipped || ! $options->prune ? 0 : count($dropped) - ($rehoming ? 0 : count($droppedInUse))),
            keptInUse: $pruneSkipped || $rehoming ? 0 : count($droppedInUse),
            rehomed: $options->dryRun ? ($rehoming ? $accountsAffected : 0) : $rehomed,
            accountsAffected: $accountsAffected,
            regionsRemoved: $regionsRemoved,
            countriesRemoved: $countriesRemoved,
            pruneSkipped: $pruneSkipped,
        );
    }

    /**
     * A given local path, or the file downloaded from GeoNames.
     *
     * @param  Closure(string): void  $log
     */
    private function source(?string $local, string $remoteName, string $workDir, Closure $log): string
    {
        if ($local !== null && $local !== '') {
            if (! is_file($local)) {
                throw new RuntimeException("File not found: {$local}");
            }

            return $local;
        }

        $url = rtrim((string) config('places.base_url'), '/').'/'.$remoteName;
        $target = $workDir.'/'.$remoteName;
        $log("Downloading {$url}");
        $response = Http::timeout(300)->withOptions(['sink' => $target])->get($url);
        if (! $response->successful()) {
            throw new RuntimeException("Download failed ({$response->status()}): {$url}");
        }

        return $target;
    }

    /**
     * Extracts the dataset's text file; an uploaded zip holding a single .txt
     * file under another name is accepted too.
     */
    private function unzip(string $zipPath, string $entry, string $workDir): string
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open the cities zip file.');
        }
        try {
            if ($zip->locateName($entry) === false) {
                $texts = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    if (str_ends_with(strtolower($name), '.txt') && ! str_contains($name, '/')) {
                        $texts[] = $name;
                    }
                }
                if (count($texts) !== 1) {
                    throw new RuntimeException("{$entry} not found in the cities zip file.");
                }
                $entry = $texts[0];
            }
            if (! $zip->extractTo($workDir, $entry)) {
                throw new RuntimeException("Could not extract {$entry}.");
            }
        } finally {
            $zip->close();
        }

        return $workDir.'/'.$entry;
    }

    /**
     * @return array<string, array{code: string, name: string, search_name: string}>
     */
    private function parseCountries(string $path): array
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
        if ($rows === []) {
            throw new RuntimeException('No countries found; is this GeoNames countryInfo.txt?');
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $countries
     * @return array<string, array{country_code: string, code: string, name: string, search_name: string}>
     */
    private function parseRegions(string $path, array $countries): array
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

        return $rows;
    }

    /**
     * @param  array<string, array{code: string, name: string, search_name: string}>  $countries
     */
    private function writeCountries(array $countries): void
    {
        foreach (array_chunk(array_values($countries), self::CHUNK) as $chunk) {
            DB::table('place_countries')->upsert($chunk, ['code'], ['name', 'search_name']);
        }
    }

    /**
     * Inserts new regions and renames changed ones in place by id. An upsert
     * would spend an AUTO_INCREMENT value per existing row on MariaDB/MySQL
     * and exhaust the SMALLINT key after a few dozen refreshes.
     *
     * @param  array<string, array{country_code: string, code: string, name: string, search_name: string}>  $rows
     * @return array<string, int> "US.OR" => region id
     */
    private function writeRegions(array $rows): array
    {
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
     * Upserts cities in multi-row statements and returns the ids it saw.
     *
     * @param  array<string, mixed>  $countries
     * @param  array<string, int>  $regions
     * @return array<int, true>
     */
    private function writeCities(string $path, array $countries, array $regions, int $batch): array
    {
        $seen = [];
        $rows = [];
        foreach ($this->cityRows($path, $countries, $regions, $batch) as $row) {
            $seen[$row['id']] = true;
            $rows[] = $row;
            if (count($rows) >= self::CHUNK) {
                $this->upsertCities($rows);
                $rows = [];
            }
        }
        $this->upsertCities($rows);

        return $seen;
    }

    /**
     * @param  array<string, mixed>  $countries
     * @param  array<string, int>  $regions
     * @return Generator<int, array{id: int, name: string, search_name: string, region_id: int|null, country_code: string, lat_e4: int, lng_e4: int, population: int, import_batch: int}>
     */
    private function cityRows(string $path, array $countries, array $regions, int $batch): Generator
    {
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

            yield [
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
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsertCities(array $rows): void
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
     * @return Generator<int, list<string>>
     */
    private function lines(string $path): Generator
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
