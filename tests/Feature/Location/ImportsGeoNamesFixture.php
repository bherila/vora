<?php

namespace Tests\Feature\Location;

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
}
