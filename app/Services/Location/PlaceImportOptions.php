<?php

namespace App\Services\Location;

/**
 * How one GeoNames import runs. "update" refreshes and prunes cities nobody
 * uses; "replace" also prunes unused regions and countries and, with
 * $rehome, moves people off cities the new data dropped.
 */
final readonly class PlaceImportOptions
{
    public const MODE_UPDATE = 'update';

    public const MODE_REPLACE = 'replace';

    public const MODES = [self::MODE_UPDATE, self::MODE_REPLACE];

    /** GeoNames city dumps by minimum population. */
    public const DATASETS = ['cities500', 'cities1000', 'cities5000', 'cities15000'];

    public function __construct(
        public string $dataset,
        public string $mode = self::MODE_UPDATE,
        public bool $rehome = false,
        public bool $dryRun = false,
        public bool $prune = true,
        public int $minimumRows = 1000,
        public ?string $citiesPath = null,
        public ?string $admin1Path = null,
        public ?string $countriesPath = null,
    ) {}

    public function replaces(): bool
    {
        return $this->mode === self::MODE_REPLACE;
    }
}
