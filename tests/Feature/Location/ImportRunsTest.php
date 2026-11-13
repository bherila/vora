<?php

namespace Tests\Feature\Location;

use App\Models\Place;
use App\Models\PlaceImportRun;
use App\Models\User;
use App\Services\Location\PlaceImportRuns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/**
 * Concurrency, recovery and input limits for GeoNames imports.
 */
class ImportRunsTest extends TestCase
{
    use ImportsGeoNamesFixture;
    use RefreshDatabase;

    private User $admin;

    private PlaceImportRuns $runs;

    protected function setUp(): void
    {
        parent::setUp();
        config(['places.minimum_rows' => 1]);
        $this->admin = User::factory()->admin()->create();
        $this->runs = app(PlaceImportRuns::class);
        File::deleteDirectory(storage_path('app/private/geonames'));
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory(storage_path('app/private/geonames')));
    }

    public function test_every_entry_point_waits_for_the_one_import_lock(): void
    {
        $dir = base_path('tests/Fixtures/geonames');
        $lock = Cache::lock('places:import', 600);
        $this->assertTrue($lock->get());

        // A manual import while another holds the lock writes nothing.
        $this->artisan('places:import', ['--cities' => "{$dir}/cities.txt", '--admin1' => "{$dir}/admin1CodesASCII.txt", '--countries' => "{$dir}/countryInfo.txt", '--min-rows' => 1])
            ->expectsOutputToContain('Another import is running')
            ->assertFailed();
        $this->assertSame(0, Place::query()->count());

        // A queued request stays queued until the lock is free.
        $this->runs->queue(['mode' => 'update', 'rehome' => false, 'dry_run' => true], 'admin', $this->admin);
        $this->artisan('places:run-requested')->assertSuccessful();
        $this->assertNotNull($this->runs->nextPending());

        $lock->release();
        $this->importGeoNamesFixture();
        $this->assertSame(9, Place::query()->count());
    }

    public function test_a_request_queued_while_another_is_claimed_is_kept(): void
    {
        $first = $this->runs->queue(['mode' => 'update', 'rehome' => false, 'dry_run' => true], 'admin', $this->admin);
        $claimed = $this->runs->claim();
        $this->assertSame($first->id, $claimed?->id);

        $second = $this->runs->queue(['mode' => 'replace', 'rehome' => true, 'dry_run' => true], 'admin', $this->admin);

        $this->assertSame(PlaceImportRun::RUNNING, $first->refresh()->state);
        $this->assertSame($second->id, $this->runs->nextPending()?->id);
        // A claimed run cannot be cancelled, and keeps its files.
        $this->assertFalse($this->runs->cancel($first->id));
    }

    public function test_cancelling_a_run_another_process_just_claimed_is_refused_and_keeps_its_files(): void
    {
        $id = '6f0c2a4e-5b1d-4c3e-9a8f-1b2c3d4e5f60';
        File::ensureDirectoryExists($this->runs->filesDir($id));
        File::put($this->runs->filesDir($id).'/countryInfo.txt', 'x');
        $run = $this->runs->queue(['mode' => 'update', 'rehome' => false, 'dry_run' => false, 'files_run_id' => $id], 'admin', $this->admin, $id);
        $this->runs->claim();

        $this->actingAs($this->admin)->deleteJson('/api/admin/places/import', ['id' => $run->id])->assertStatus(409);
        $this->runs->cleanup();

        $this->assertFileExists($this->runs->filesDir($id).'/countryInfo.txt');
    }

    public function test_the_monthly_refresh_never_displaces_an_admin_request(): void
    {
        $admin = $this->runs->queue(['mode' => 'replace', 'rehome' => false, 'dry_run' => true], 'admin', $this->admin);

        $this->assertNull($this->runs->queueScheduledRefresh());
        $this->assertSame(PlaceImportRun::PENDING, $admin->refresh()->state);

        // Even if both end up pending, the admin's runs first.
        PlaceImportRun::query()->create([
            'id' => '0c9f1a2b-3c4d-4e5f-8a6b-7c8d9e0f1a2b', 'state' => 'pending', 'source' => 'schedule', 'dataset' => 'cities5000',
            'mode' => 'update', 'requested_at' => now()->addSecond(),
        ]);
        $this->assertSame($admin->id, $this->runs->claim()?->id);
    }

    public function test_a_killed_import_is_shown_as_interrupted_and_does_not_block_new_ones(): void
    {
        PlaceImportRun::query()->create([
            'id' => '1d2e3f40-5a6b-4c7d-8e9f-0a1b2c3d4e5f', 'state' => 'running', 'source' => 'admin', 'dataset' => 'cities5000',
            'mode' => 'update', 'requested_at' => now()->subHour(), 'started_at' => now()->subHour(), 'heartbeat_at' => now()->subMinutes(11),
        ]);

        $this->actingAs($this->admin)->getJson('/api/admin/places')
            ->assertJsonPath('data.status.state', 'interrupted')
            ->assertJsonPath('data.status.error', 'The import stopped without finishing (its process ended). Start it again.');

        $this->postJson('/api/admin/places/import', ['source' => 'download', 'mode' => 'update', 'dry_run' => true])->assertStatus(202);
    }

    public function test_missing_files_are_reported_as_a_failed_run(): void
    {
        $id = '2e3f4a5b-6c7d-4e8f-9a0b-1c2d3e4f5a6b';
        $this->runs->queue(['mode' => 'update', 'rehome' => false, 'dry_run' => false, 'files_run_id' => $id], 'admin', $this->admin, $id);

        $this->artisan('places:run-requested')->assertFailed();

        $this->actingAs($this->admin)->getJson('/api/admin/places')
            ->assertJsonPath('data.status.state', 'failed')
            ->assertJsonPath('data.status.error', 'The GeoNames files for this run are missing. Upload them again.');
    }

    public function test_applying_an_older_preview_after_a_newer_one_is_refused(): void
    {
        $this->fakeDownloads();
        $this->actingAs($this->admin)->postJson('/api/admin/places/import', ['source' => 'download', 'mode' => 'update', 'dry_run' => true])->assertStatus(202);
        $this->artisan('places:run-requested')->assertSuccessful();
        $older = $this->runs->lastFinished()?->id;
        $this->postJson('/api/admin/places/import', ['source' => 'download', 'mode' => 'replace', 'rehome' => true, 'dry_run' => true])->assertStatus(202);
        $this->artisan('places:run-requested')->assertSuccessful();
        $newer = $this->runs->lastFinished();

        // The older preview's files were cleaned up once a newer one existed.
        $this->postJson('/api/admin/places/import', ['source' => 'preview', 'preview_id' => $older])->assertStatus(409);

        // Applying the newer one copies its own options and files, read once.
        $this->postJson('/api/admin/places/import', ['source' => 'preview', 'preview_id' => $newer?->id])->assertStatus(202);
        $pending = $this->runs->nextPending();
        $this->assertSame([$newer?->files_run_id, 'replace', true, $newer?->id], [$pending?->files_run_id, $pending?->mode, $pending?->rehome, $pending?->preview_of]);
    }

    public function test_a_preview_is_stale_once_another_import_completes(): void
    {
        $this->fakeDownloads();
        $this->actingAs($this->admin)->postJson('/api/admin/places/import', ['source' => 'download', 'mode' => 'update', 'dry_run' => true])->assertStatus(202);
        $this->artisan('places:run-requested')->assertSuccessful();
        $preview = $this->runs->lastFinished()?->id;

        $this->importGeoNamesFixture();

        $this->postJson('/api/admin/places/import', ['source' => 'preview', 'preview_id' => $preview])
            ->assertStatus(409)
            ->assertJsonPath('message', 'The city list changed after that preview ran. Preview again.');
    }

    public function test_a_city_dropped_by_an_import_cannot_be_saved_again(): void
    {
        $this->importGeoNamesFixture();
        $owner = User::factory()->approved()->create(['place_id' => 5735238]);
        $other = User::factory()->approved()->create();
        $cities = $this->citiesWithoutSalem();

        // Update keeps Salem for its owner but drops it from the list.
        $this->importGeoNamesFixture(['--cities' => $cities]);
        $this->actingAs($owner)->putJson('/api/location', ['place_id' => 5735238, 'country_audience' => 'everyone', 'city_audience' => 'mutuals'])->assertOk();
        $this->actingAs($other)->putJson('/api/location', ['place_id' => 5735238, 'country_audience' => 'everyone', 'city_audience' => 'everyone'])
            ->assertUnprocessable()->assertJsonValidationErrors('place_id');

        // Replace moves the owner; their stale editor cannot put them back.
        $this->importGeoNamesFixture(['--cities' => $cities, '--mode' => 'replace', '--rehome' => true]);
        $this->assertNotSame(5735238, $owner->fresh()->place_id);
        $this->assertNull(Place::query()->find(5735238));
        $this->actingAs($owner->fresh())->putJson('/api/location', ['place_id' => 5735238, 'country_audience' => 'everyone', 'city_audience' => 'everyone'])
            ->assertUnprocessable()->assertJsonValidationErrors('place_id');
        $this->assertNotNull($owner->fresh()->place_id);
    }

    public function test_preview_and_import_report_the_same_affected_accounts_when_rehoming(): void
    {
        $this->importGeoNamesFixture();
        User::factory()->approved()->create(['place_id' => 5735238]);
        $options = ['--cities' => $this->citiesWithoutSalem(), '--mode' => 'replace', '--rehome' => true];

        $this->importGeoNamesFixture($options + ['--dry-run' => true]);
        $preview = $this->lastRun()['result'];
        $this->importGeoNamesFixture($options);
        $applied = $this->lastRun()['result'];

        $this->assertSame(1, $preview['accounts_affected']);
        $this->assertSame($preview['accounts_affected'], $applied['accounts_affected']);
        $this->assertSame($preview['rehomed'], $applied['rehomed']);
    }

    public function test_an_oversized_zip_entry_is_refused_before_extraction(): void
    {
        config(['places.max_file_mb' => 1]);
        $zipPath = tempnam(sys_get_temp_dir(), 'zip').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('cities5000.txt', str_repeat("\n", 2 * 1024 * 1024));
        $zip->close();
        $this->assertLessThan(1024 * 1024, filesize($zipPath));
        $dir = base_path('tests/Fixtures/geonames');

        $this->artisan('places:import', ['--cities' => $zipPath, '--admin1' => "{$dir}/admin1CodesASCII.txt", '--countries' => "{$dir}/countryInfo.txt"])
            ->expectsOutputToContain('larger than the 1 MB limit')
            ->assertFailed();
        unlink($zipPath);
        $this->assertSame(0, Place::query()->count());
    }

    public function test_overlong_lines_and_impossible_coordinates_are_refused(): void
    {
        $dir = base_path('tests/Fixtures/geonames');
        $long = tempnam(sys_get_temp_dir(), 'cities');
        file_put_contents($long, str_repeat('x', 70000)."\n");
        $this->artisan('places:import', ['--cities' => $long, '--admin1' => "{$dir}/admin1CodesASCII.txt", '--countries' => "{$dir}/countryInfo.txt"])
            ->expectsOutputToContain('is longer than')
            ->assertFailed();
        unlink($long);

        $bad = tempnam(sys_get_temp_dir(), 'cities');
        $row = static fn (string $id, string $lat, string $lng): string => implode("\t", [$id, 'Nowhere', 'Nowhere', '', $lat, $lng, 'P', 'PPL', 'US', '', 'OR', '', '', '', '100', '', '', 'UTC', '2024-01-01'])."\n";
        file_put_contents($bad, $row('7000001', '95', '10').$row('7000002', '10', '190').$row('99999999999', '10', '10').$row('7000003', '45.5', '-122.6'));
        $this->artisan('places:import', ['--cities' => $bad, '--admin1' => "{$dir}/admin1CodesASCII.txt", '--countries' => "{$dir}/countryInfo.txt", '--min-rows' => 1])
            ->assertSuccessful();
        unlink($bad);

        $this->assertSame([7000003], Place::query()->pluck('id')->all());
    }

    private function citiesWithoutSalem(): string
    {
        $lines = array_filter(
            file(base_path('tests/Fixtures/geonames/cities.txt')) ?: [],
            fn (string $line): bool => strtok($line, "\t") !== '5735238',
        );
        $path = tempnam(sys_get_temp_dir(), 'cities');
        file_put_contents($path, implode('', $lines));
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    private function fakeDownloads(): void
    {
        $dir = base_path('tests/Fixtures/geonames');
        $zipPath = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFile("{$dir}/cities.txt", 'cities5000.txt');
        $zip->close();
        $zipBody = (string) file_get_contents($zipPath);
        unlink($zipPath);

        config(['places.base_url' => 'https://geonames.example.test/dump']);
        Http::fake([
            'geonames.example.test/dump/countryInfo.txt' => fn () => Http::response((string) file_get_contents("{$dir}/countryInfo.txt")),
            'geonames.example.test/dump/admin1CodesASCII.txt' => fn () => Http::response((string) file_get_contents("{$dir}/admin1CodesASCII.txt")),
            'geonames.example.test/dump/cities5000.zip' => fn () => Http::response($zipBody),
        ]);
    }
}
