<?php

/*
|--------------------------------------------------------------------------
| City gazetteer (GeoNames)
|--------------------------------------------------------------------------
|
| Location pickers search a local copy of the GeoNames city list, loaded by
| `php artisan places:import`. The data is not shipped with releases: run the
| command (or enable the scheduled refresh) on each instance to load or update
| it at any time. GeoNames data is CC BY 4.0 — keep the attribution shown in
| the location picker.
|
*/

return [
    // Default GeoNames city dump: cities500, cities1000, cities5000, cities15000
    // (minimum population). Smaller numbers mean more, smaller places. The
    // dataset chosen on Admin → City list takes precedence.
    'dataset' => env('PLACES_DATASET', 'cities5000'),

    'base_url' => env('PLACES_BASE_URL', 'https://download.geonames.org/export/dump'),

    // Refuse to prune when an import parsed fewer cities than this: a
    // truncated download must never empty the table.
    'minimum_rows' => (int) env('PLACES_MINIMUM_ROWS', 1000),

    // Default for the monthly refresh; Admin → City list can turn it on or
    // off. The command can always be run by hand.
    'auto_refresh' => (bool) env('PLACES_AUTO_REFRESH', false),

    // Largest GeoNames file (downloaded, uploaded or extracted from a zip)
    // the importer reads, in MB. cities500.txt is about 45 MB.
    'max_file_mb' => (int) env('PLACES_MAX_FILE_MB', 512),

    // Where downloaded and uploaded GeoNames files and import scratch space
    // live. Private; never served.
    'storage_path' => env('PLACES_STORAGE_PATH', storage_path('app/private/geonames')),
];
