<?php

namespace Tests\Feature\Location;

use App\Models\Place;
use App\Models\User;
use App\Services\Location\PlaceDirectory;
use App\Services\Location\PlaceImportRequests;
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

    public function test_reimport_keeps_region_ids_and_applies_renames(): void
    {
        $this->importGeoNamesFixture();
        $ids = DB::table('place_regions')->orderBy('id')->pluck('id', 'code')->all();

        $dir = base_path('tests/Fixtures/geonames');
        $admin1 = tempnam(sys_get_temp_dir(), 'admin1');
        file_put_contents($admin1, str_replace("US.OR\tOregon\tOregon", "US.OR\tOregon State\tOregon State", (string) file_get_contents("{$dir}/admin1CodesASCII.txt")));
        $this->importGeoNamesFixture(['--admin1' => $admin1]);
        unlink($admin1);

        $this->assertSame($ids, DB::table('place_regions')->orderBy('id')->pluck('id', 'code')->all());
        $this->assertSame('Oregon State', DB::table('place_regions')->where('code', 'OR')->value('name'));
        $this->assertSame('Portland, Oregon State, United States', Place::query()->findOrFail(5746545)->label());
    }

    public function test_dry_run_reports_changes_without_writing(): void
    {
        $this->importGeoNamesFixture();
        User::factory()->approved()->create(['place_id' => 5735238]);
        $before = Place::query()->orderBy('id')->get()->toArray();

        $this->importGeoNamesFixture(['--cities' => $this->citiesWithout(['5735238', '2198148']), '--dry-run' => true]);

        $this->assertSame($before, Place::query()->orderBy('id')->get()->toArray());
        $status = app(PlaceImportRequests::class)->status();
        $this->assertSame('succeeded', $status['state']);
        $this->assertSame(['parsed' => 7, 'added' => 0, 'updated' => 7, 'removed' => 1, 'kept_in_use' => 1, 'accounts_affected' => 1], array_intersect_key(
            $status['result'],
            array_flip(['parsed', 'added', 'updated', 'removed', 'kept_in_use', 'accounts_affected']),
        ));
    }

    public function test_kept_cities_leave_search_but_stay_on_their_owners_profile(): void
    {
        $this->importGeoNamesFixture();
        $owner = User::factory()->approved()->create(['place_id' => 5735238, 'location_city_audience' => 'everyone', 'location_country_audience' => 'everyone']);

        $this->importGeoNamesFixture(['--cities' => $this->citiesWithout(['5735238'])]);

        $this->assertNotNull(Place::query()->find(5735238));
        $this->assertSame([], app(PlaceDirectory::class)->search('salem')->all());
        $viewer = User::factory()->approved()->create();
        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}")->assertJsonPath('data.location.label', 'Salem, Oregon, United States');
    }

    public function test_replace_moves_people_off_dropped_cities_and_prunes_unused_lookups(): void
    {
        $this->importGeoNamesFixture();
        $owner = User::factory()->approved()->create(['place_id' => 5735238]);
        // Drop Salem (in use) and every Fijian city.
        $cities = $this->citiesWithout(['5735238', '2198148', '9999993']);

        $this->importGeoNamesFixture(['--cities' => $cities, '--mode' => 'replace', '--rehome' => true]);

        $this->assertNull(Place::query()->find(5735238));
        // Beaverton is the nearest remaining city to Salem in the fixture.
        $this->assertSame(5747882, $owner->fresh()->place_id);
        $this->assertSame(0, DB::table('place_regions')->where('country_code', 'FJ')->count());
        $this->assertSame(0, DB::table('place_countries')->where('code', 'FJ')->count());
        $this->assertSame(1, app(PlaceImportRequests::class)->status()['result']['rehomed']);
    }

    public function test_replace_preview_reports_the_lookups_the_import_removes(): void
    {
        $this->importGeoNamesFixture();
        User::factory()->approved()->create(['place_id' => 5735238]);
        $cities = $this->citiesWithout(['5735238', '2198148', '9999993']);
        $requests = app(PlaceImportRequests::class);

        foreach ([false, true] as $rehome) {
            $options = ['--cities' => $cities, '--mode' => 'replace', '--rehome' => $rehome];
            $this->importGeoNamesFixture($options + ['--dry-run' => true]);
            $preview = $requests->status()['result'];
            $this->assertSame(1, $preview['countries_removed'], 'Fiji');
            // Fiji's only region; Oregon keeps Portland and Beaverton.
            $this->assertSame(1, $preview['regions_removed']);
        }

        $this->importGeoNamesFixture(['--cities' => $cities, '--mode' => 'replace', '--rehome' => true]);
        $applied = $requests->status()['result'];
        $this->assertSame([$preview['regions_removed'], $preview['countries_removed'], $preview['removed'], $preview['rehomed']], [
            $applied['regions_removed'], $applied['countries_removed'], $applied['removed'], $applied['rehomed'],
        ]);
    }

    public function test_update_mode_never_moves_people_or_prunes_lookups(): void
    {
        $this->importGeoNamesFixture();
        $owner = User::factory()->approved()->create(['place_id' => 5735238]);

        $this->importGeoNamesFixture(['--cities' => $this->citiesWithout(['5735238', '2198148', '9999993']), '--rehome' => true]);

        $this->assertSame(5735238, $owner->fresh()->place_id);
        $this->assertSame(1, DB::table('place_countries')->where('code', 'FJ')->count());
    }

    public function test_accepts_a_cities_zip_whose_text_file_has_another_name(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'zip').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile(base_path('tests/Fixtures/geonames/cities.txt'), 'my-export.txt');
        $zip->close();

        $this->importGeoNamesFixture(['--cities' => $zipPath]);
        unlink($zipPath);

        $this->assertSame(9, Place::query()->count());
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

    /**
     * A copy of the fixture cities file without the given GeoNames ids.
     *
     * @param  list<string>  $ids
     */
    private function citiesWithout(array $ids): string
    {
        $lines = array_filter(
            file(base_path('tests/Fixtures/geonames/cities.txt')) ?: [],
            fn (string $line): bool => ! in_array(strtok($line, "\t"), $ids, true),
        );
        $path = tempnam(sys_get_temp_dir(), 'cities');
        file_put_contents($path, implode('', $lines));
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }
}
