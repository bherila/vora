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

    /** Longest line accepted in a GeoNames file. */
    private const MAX_LINE_BYTES = 64 * 1024;

    /** Largest GeoNames id that fits the unsigned INT key. */
    private const MAX_ID = 4294967295;

    public function __construct(
        private readonly PlaceDirectory $directory,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Callers must hold the import lock ({@see PlaceImportRuns::exclusively()}),
     * which keeps every entry point from interleaving writes.
     *
     * @param  (Closure(string): void)|null  $log
     * @param  (Closure(): void)|null  $heartbeat  called as work progresses
     */
    public function run(PlaceImportOptions $options, ?Closure $log = null, ?Closure $heartbeat = null): PlaceImportResult
    {
        if (! in_array($options->dataset, PlaceImportOptions::DATASETS, true)) {
            throw new RuntimeException("Unknown dataset [{$options->dataset}].");
        }
        $log ??= static function (string $message): void {};
        $heartbeat ??= static function (): void {};

        $workDir = PlaceImportRuns::storageRoot().'/work-'.Str::random(12);
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

            // Read and validate the whole cities file before writing anything,
            // so a truncated or malformed file never touches the live list.
            [$count, $usedRegionKeys] = $this->scanCities($citiesPath, $countries);
            if ($options->prune && $count < $options->minimumRows) {
                throw new RuntimeException("Only {$count} cities were read, fewer than the {$options->minimumRows} minimum; nothing was imported.");
            }
            $heartbeat();

            $existing = Place::query()->pluck('id')->map(fn ($id): int => (int) $id)->flip()->all();
            $inUse = DB::table('users')->whereNotNull('place_id')->distinct()->pluck('place_id')->map(fn ($id): int => (int) $id)->flip()->all();

            if ($options->dryRun) {
                return $this->preview($options, $citiesPath, $countries, $regionRows, $existing, $inUse);
            }

            $this->writeCountries($countries);
            $regions = $this->writeRegions($regionRows, $usedRegionKeys);

            // Strictly increasing, so a row from any earlier run differs. The
            // import lock keeps two runs from choosing batches concurrently.
            $batch = max(now()->getTimestamp(), (int) Place::query()->max('import_batch') + 1);
            $seen = $this->writeCities($citiesPath, $countries, $regions, $batch, $heartbeat);
            $log('Imported '.count($seen)." places from {$options->dataset}.");

            // Counted before anything moves, so the result matches the preview.
            $accountsAffected = $this->accountsOn(array_intersect_key(array_diff_key($existing, $seen), $inUse));

            if (! $options->prune) {
                return $this->result($options, $seen, $existing, $inUse, $accountsAffected, removed: 0, rehomed: 0, regionsRemoved: 0, countriesRemoved: 0, pruneSkipped: true);
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
                    [$rehomed, $rehomedPlaces] = $this->rehome($batch, $heartbeat);
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

            return $this->result($options, $seen, $existing, $inUse, $accountsAffected, $removed, $rehomed, $regionsRemoved, $countriesRemoved, pruneSkipped: false);
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * A dry run: reads every file and works out what a real run would add,
     * update and remove, including the regions and countries Replace mode
     * would prune, without writing anything.
     *
     * @param  array<string, array{code: string, name: string, search_name: string}>  $countries
     * @param  array<string, array{country_code: string, code: string, name: string, search_name: string}>  $regionRows
     * @param  array<int, true>  $existing
     * @param  array<int, true>  $inUse
     */
    private function preview(PlaceImportOptions $options, string $citiesPath, array $countries, array $regionRows, array $existing, array $inUse): PlaceImportResult
    {
        // A real run inserts only regions some city uses, so only existing
        // regions can be pruned.
        $existingRegionKeys = DB::table('place_regions')->get(['country_code', 'code'])
            ->map(fn (object $r): string => $r->country_code.'.'.$r->code)->all();
        $regionIndex = array_flip(array_values(array_unique([...$existingRegionKeys, ...array_keys($regionRows)])));

        $seen = [];
        $usedRegions = [];
        $usedCountries = [];
        foreach ($this->cityRows($citiesPath, $countries, $regionIndex, 0) as $row) {
            $seen[$row['id']] = true;
            if ($row['region_id'] !== null) {
                $usedRegions[$row['region_id']] = true;
            }
            $usedCountries[$row['country_code']] = true;
        }
        $pruneSkipped = ! $options->prune;

        $regionsRemoved = 0;
        $countriesRemoved = 0;
        if ($options->replaces() && ! $pruneSkipped) {
            // Cities kept for their owners keep their region and country too,
            // unless their owners are moved and the cities removed.
            $survivors = $options->rehome ? [] : array_keys(array_intersect_key(array_diff_key($existing, $seen), $inUse));
            foreach (array_chunk($survivors, self::ID_CHUNK) as $ids) {
                foreach (DB::table('places')->leftJoin('place_regions', 'place_regions.id', '=', 'places.region_id')
                    ->whereIn('places.id', $ids)
                    ->get(['places.country_code', 'place_regions.country_code as region_country', 'place_regions.code as region_code']) as $place) {
                    $usedCountries[$place->country_code] = true;
                    if ($place->region_code !== null) {
                        $usedRegions[$regionIndex[$place->region_country.'.'.$place->region_code]] = true;
                    }
                }
            }
            $regionsRemoved = count(array_filter($existingRegionKeys, fn (string $key): bool => ! isset($usedRegions[$regionIndex[$key]])));
            $allCountries = DB::table('place_countries')->pluck('code')->merge(array_keys($countries))->unique();
            $countriesRemoved = $allCountries->reject(fn (string $code): bool => isset($usedCountries[$code]))->count();
        }

        $accountsAffected = $this->accountsOn(array_intersect_key(array_diff_key($existing, $seen), $inUse));

        return $this->result($options, $seen, $existing, $inUse, $accountsAffected, removed: null, rehomed: 0, regionsRemoved: $regionsRemoved, countriesRemoved: $countriesRemoved, pruneSkipped: $pruneSkipped);
    }

    /**
     * Moves accounts off cities this import dropped, to the nearest city it
     * kept, then deletes the dropped cities. Each city is moved and deleted in
     * one transaction, and deleted only if no account points at it any more,
     * so a location saved concurrently is never cleared by the foreign key.
     * Returns [accounts, places].
     *
     * @param  Closure(): void  $heartbeat
     * @return array{int, int}
     */
    private function rehome(int $batch, Closure $heartbeat): array
    {
        $accounts = 0;
        $places = 0;
        $stale = Place::query()->where('import_batch', '<', $batch)->get();
        foreach ($stale as $place) {
            $target = $this->directory->nearest($place->latitude, $place->longitude, anywhere: true);
            if (! $target instanceof Place || $target->id === $place->id) {
                continue;
            }
            [$moved, $deleted] = DB::transaction(function () use ($place, $target): array {
                $moved = DB::table('users')->where('place_id', $place->id)->update(['place_id' => $target->id]);
                $deleted = DB::table('places')->where('id', $place->id)
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('users')->where('users.place_id', $place->id))
                    ->delete();

                return [$moved, $deleted];
            });
            $accounts += $moved;
            $places += $deleted;
            $heartbeat();
        }

        return [$accounts, $places];
    }

    /**
     * Accounts whose city is one of $placeIds.
     *
     * @param  array<int, true>  $placeIds
     */
    private function accountsOn(array $placeIds): int
    {
        $count = 0;
        foreach (array_chunk(array_keys($placeIds), self::ID_CHUNK) as $ids) {
            $count += DB::table('users')->whereIn('place_id', $ids)->count();
        }

        return $count;
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
        int $accountsAffected,
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
            $this->assertFileSize($local);

            return $local;
        }

        return $this->fetch($remoteName, $workDir.'/'.$remoteName, $log);
    }

    /**
     * Downloads a dataset's three GeoNames files into $dir under the names an
     * uploaded set uses, so a preview can be applied to exactly these files.
     *
     * @param  (Closure(string): void)|null  $log
     */
    public function download(string $dataset, string $dir, ?Closure $log = null): void
    {
        if (! in_array($dataset, PlaceImportOptions::DATASETS, true)) {
            throw new RuntimeException("Unknown dataset [{$dataset}].");
        }
        $log ??= static function (string $message): void {};

        $this->fetch('countryInfo.txt', $dir.'/countryInfo.txt', $log);
        $this->fetch('admin1CodesASCII.txt', $dir.'/admin1CodesASCII.txt', $log);
        $this->fetch("{$dataset}.zip", $dir.'/cities.zip', $log);
    }

    /**
     * @param  Closure(string): void  $log
     */
    private function fetch(string $remoteName, string $target, Closure $log): string
    {
        $url = rtrim((string) config('places.base_url'), '/').'/'.$remoteName;
        $log("Downloading {$url}");
        $response = Http::timeout(300)->withOptions(['sink' => $target])->get($url);
        if (! $response->successful()) {
            throw new RuntimeException("Download failed ({$response->status()}): {$url}");
        }
        $this->assertFileSize($target);

        return $target;
    }

    /**
     * Largest file, downloaded, uploaded or extracted, the importer reads
     * (`places.max_file_mb`; cities500.txt is about 45 MB).
     */
    private function maxBytes(): int
    {
        return max(1, (int) config('places.max_file_mb', 512)) * 1024 * 1024;
    }

    private function assertFileSize(string $path): void
    {
        $size = filesize($path);
        if ($size === false || $size > $this->maxBytes()) {
            throw new RuntimeException(basename($path).' is larger than the '.intdiv($this->maxBytes(), 1024 * 1024).' MB limit.');
        }
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
            // The archive's own size header can lie, so check it and also cap
            // the bytes actually copied.
            $stat = $zip->statName($entry);
            if ($stat === false || $stat['size'] > $this->maxBytes()) {
                throw new RuntimeException("{$entry} is larger than the ".intdiv($this->maxBytes(), 1024 * 1024).' MB limit.');
            }
            $target = $workDir.'/cities.txt';
            $in = $zip->getStream($entry);
            $out = fopen($target, 'wb');
            if ($in === false || $out === false) {
                throw new RuntimeException("Could not extract {$entry}.");
            }
            try {
                $copied = stream_copy_to_stream($in, $out, $this->maxBytes() + 1);
            } finally {
                fclose($in);
                fclose($out);
            }
            if ($copied === false || $copied > $this->maxBytes()) {
                throw new RuntimeException("{$entry} is larger than the ".intdiv($this->maxBytes(), 1024 * 1024).' MB limit.');
            }
        } finally {
            $zip->close();
        }

        return $target;
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
     * Inserts the new regions some city uses and renames changed ones in place
     * by id. Never inserting a region only to prune it, and never upserting
     * (which spends an AUTO_INCREMENT value per existing row on MariaDB/MySQL),
     * keeps repeated imports from exhausting the SMALLINT key.
     *
     * @param  array<string, array{country_code: string, code: string, name: string, search_name: string}>  $rows
     * @param  array<string, true>  $used  region keys the cities file refers to
     * @return array<string, int> "US.OR" => region id
     */
    private function writeRegions(array $rows, array $used): array
    {
        $existing = DB::table('place_regions')->get(['id', 'country_code', 'code', 'name', 'search_name'])
            ->keyBy(fn (object $r): string => $r->country_code.'.'.$r->code);
        $inserts = [];
        foreach ($rows as $key => $row) {
            $current = $existing->get($key);
            if ($current === null) {
                if (isset($used[$key])) {
                    $inserts[] = $row;
                }
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
     * @param  Closure(): void  $heartbeat
     * @return array<int, true>
     */
    private function writeCities(string $path, array $countries, array $regions, int $batch, Closure $heartbeat): array
    {
        $seen = [];
        $rows = [];
        foreach ($this->cityRows($path, $countries, $regions, $batch) as $row) {
            $seen[$row['id']] = true;
            $rows[] = $row;
            if (count($rows) >= self::CHUNK) {
                $this->upsertCities($rows);
                $rows = [];
                $heartbeat();
            }
        }
        $this->upsertCities($rows);

        return $seen;
    }

    /**
     * Whether a cities-file row is a populated place someone lives in today,
     * in a known country, with usable coordinates.
     *
     * Columns: geonameid, name, asciiname, alternatenames, lat, lng, class,
     * code, country, cc2, admin1, admin2, admin3, admin4, population, ...
     *
     * @param  list<string>  $c
     * @param  array<string, mixed>  $countries
     */
    private function importable(array $c, array $countries): bool
    {
        return count($c) >= 15
            && ctype_digit($c[0])
            && $c[6] === 'P'
            && ! in_array($c[7], self::SKIPPED_FEATURE_CODES, true)
            && strlen($c[0]) <= 10 && (int) $c[0] > 0 && (int) $c[0] <= self::MAX_ID
            && isset($countries[$c[8]])
            && is_numeric($c[4]) && abs((float) $c[4]) <= 90
            && is_numeric($c[5]) && abs((float) $c[5]) <= 180;
    }

    /**
     * Reads the whole cities file once before anything is written: the
     * number of distinct importable cities, and the region keys ("US.OR")
     * they refer to. Malformed files fail here (see {@see self::lines()}).
     *
     * @param  array<string, mixed>  $countries
     * @return array{int, array<string, true>}
     */
    private function scanCities(string $path, array $countries): array
    {
        $ids = [];
        $used = [];
        foreach ($this->lines($path) as $c) {
            if (! $this->importable($c, $countries)) {
                continue;
            }
            $ids[(int) $c[0]] = true;
            if ($c[10] !== '') {
                $used[$c[8].'.'.$c[10]] = true;
            }
        }

        return [count($ids), $used];
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
            if (! $this->importable($c, $countries)) {
                continue;
            }
            $country = $c[8];

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
            while (($line = fgets($handle, self::MAX_LINE_BYTES + 1)) !== false) {
                if (strlen($line) >= self::MAX_LINE_BYTES && ! str_ends_with($line, "\n") && ! feof($handle)) {
                    throw new RuntimeException('A line in '.basename($path).' is longer than '.self::MAX_LINE_BYTES.' bytes; is this a GeoNames file?');
                }
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
