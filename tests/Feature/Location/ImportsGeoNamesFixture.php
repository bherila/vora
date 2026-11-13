<?php

namespace Tests\Feature\Location;

use App\Services\Location\PlaceImportRuns;

trait ImportsGeoNamesFixture
{
    /**
     * Loads the small GeoNames fixture through the real importer.
     *
     * @param  array<string, mixed>  $options
     */
    protected function importGeoNamesFixture(array $options = []): void
    {
        $dir = base_path('tests/Fixtures/geonames');

        $this->artisan('places:import', [
            '--cities' => "{$dir}/cities.txt",
            '--admin1' => "{$dir}/admin1CodesASCII.txt",
            '--countries' => "{$dir}/countryInfo.txt",
            '--min-rows' => 1,
            ...$options,
        ])->assertSuccessful();
    }

    /**
     * The latest finished run, as the admin page reports it.
     *
     * @return array{id: string, state: string, result: array<string, mixed>|null, error: string|null}|null
     */
    protected function lastRun(): ?array
    {
        $run = app(PlaceImportRuns::class)->lastFinished();

        return $run === null ? null : ['id' => $run->id, 'state' => $run->state, 'result' => $run->result, 'error' => $run->error];
    }
}
