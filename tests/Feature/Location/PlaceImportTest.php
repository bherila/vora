<?php

namespace Tests\Feature\Location;

use App\Models\Place;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class PlaceImportTest extends TestCase
{
    use ImportsGeoNamesFixture;
    use RefreshDatabase;

    public function test_import_stores_compact_cities_with_lookups(): void
    {
        $this->importGeoNamesFixture();

        // PPLX sections and non-populated features are skipped.
        $this->assertSame(9, Place::query()->count());
        $this->assertNull(Place::query()->find(9999991));
        $this->assertNull(Place::query()->find(9999992));

        $portland = Place::query()->with(['region', 'country'])->findOrFail(5746545);
        $this->assertSame(455234, $portland->lat_e4);
        $this->assertSame(-1226762, $portland->lng_e4);
        $this->assertEqualsWithDelta(45.5234, $portland->latitude, 0.00001);
        $this->assertSame('Portland, Oregon, United States', $portland->label());

        $etienne = Place::query()->with(['region', 'country'])->findOrFail(2973385);
        $this->assertSame('Saint-Étienne-du-Rouvray', $etienne->name);
        $this->assertSame('saint-etienne-du-rouvray', $etienne->search_name);
        $this->assertSame('Île-de-France', $etienne->regionName());

        $this->assertSame(3, DB::table('place_countries')->count());
    }

    public function test_reimport_is_idempotent_and_prunes_only_unused_removed_places(): void
    {
        $this->importGeoNamesFixture();
        $user = User::factory()->approved()->create(['place_id' => 5735238]);

        // Upstream drops Salem (in use) and Suva (unused).
        $trimmed = base_path('tests/Fixtures/geonames/cities.txt');
        $lines = array_filter(file($trimmed) ?: [], fn (string $line): bool => ! str_starts_with($line, '5735238') && ! str_starts_with($line, '2198148'));
        $path = tempnam(sys_get_temp_dir(), 'cities');
        file_put_contents($path, implode('', $lines));

        $this->importGeoNamesFixture(['--cities' => $path]);
        unlink($path);

        $this->assertNotNull(Place::query()->find(5735238), 'a place someone uses is kept');
        $this->assertNull(Place::query()->find(2198148));
        $this->assertSame(5735238, $user->fresh()->place_id);
        $this->assertSame(1, DB::table('place_regions')->where('code', 'OR')->count());
    }

    public function test_small_import_refuses_to_prune(): void
    {
        $this->importGeoNamesFixture();
        $empty = tempnam(sys_get_temp_dir(), 'cities');

        $this->importGeoNamesFixture(['--cities' => $empty, '--min-rows' => 5]);
        unlink($empty);

        $this->assertSame(9, Place::query()->count());
    }

    public function test_downloads_the_configured_dataset(): void
    {
        $dir = base_path('tests/Fixtures/geonames');
        $zipPath = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFile("{$dir}/cities.txt", 'cities15000.txt');
        $zip->close();

        config(['places.base_url' => 'https://geonames.example.test/dump']);
        Http::fake([
            'geonames.example.test/dump/countryInfo.txt' => Http::response((string) file_get_contents("{$dir}/countryInfo.txt")),
            'geonames.example.test/dump/admin1CodesASCII.txt' => Http::response((string) file_get_contents("{$dir}/admin1CodesASCII.txt")),
            'geonames.example.test/dump/cities15000.zip' => Http::response((string) file_get_contents($zipPath)),
        ]);
        unlink($zipPath);

        $this->artisan('places:import', ['--dataset' => 'cities15000', '--min-rows' => 1])->assertSuccessful();

        $this->assertSame(9, Place::query()->count());
    }

    public function test_rejects_unknown_dataset(): void
    {
        $this->artisan('places:import', ['--dataset' => 'everything'])->assertFailed();
    }
}
