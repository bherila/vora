<?php

namespace Tests\Feature\Location;

use App\Models\Place;
use App\Models\User;
use App\Services\Location\PlaceImportRequests;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminPlacesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['places.minimum_rows' => 1]);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_only_admins_reach_the_city_list(): void
    {
        $member = User::factory()->approved()->create();

        $this->actingAs($member)->get('/admin/places')->assertForbidden();
        $this->getJson('/api/admin/places')->assertForbidden();
        $this->postJson('/api/admin/places/import', ['source' => 'download', 'mode' => 'update'])->assertForbidden();

        $this->actingAs($this->admin)->get('/admin/places')->assertOk()->assertSee('admin-places', false);
        $this->getJson('/api/admin/places')->assertOk()->assertJsonPath('data.dataset', 'cities5000');
    }

    public function test_admin_chooses_the_dataset_and_monthly_refresh(): void
    {
        $this->actingAs($this->admin)->putJson('/api/admin/places/settings', ['dataset' => 'cities15000', 'auto_refresh' => true])
            ->assertOk()
            ->assertJsonPath('data.dataset', 'cities15000')
            ->assertJsonPath('data.auto_refresh', true);
        $this->putJson('/api/admin/places/settings', ['dataset' => 'everything', 'auto_refresh' => false])->assertUnprocessable();

        $this->assertSame('cities15000', app(PlaceImportRequests::class)->dataset());
    }

    public function test_a_queued_download_runs_once_from_the_scheduler(): void
    {
        $this->fakeGeoNames('cities5000');

        $this->actingAs($this->admin)->postJson('/api/admin/places/import', ['source' => 'download', 'mode' => 'update'])
            ->assertStatus(202)
            ->assertJsonPath('data.pending.mode', 'update');
        $this->assertSame(0, Place::query()->count());

        $this->artisan('places:run-requested')->assertSuccessful();
        $this->artisan('places:run-requested')->assertSuccessful();

        $this->assertSame(9, Place::query()->count());
        Http::assertSentCount(3);
        $this->getJson('/api/admin/places')
            ->assertJsonPath('data.pending', null)
            ->assertJsonPath('data.status.state', 'succeeded')
            ->assertJsonPath('data.status.result.added', 9)
            ->assertJsonPath('data.counts.current_places', 9);
    }

    public function test_uploaded_files_are_previewed_then_applied_without_reuploading(): void
    {
        Http::fake();
        $dir = base_path('tests/Fixtures/geonames');

        $this->actingAs($this->admin)->post('/api/admin/places/import', [
            'source' => 'upload',
            'mode' => 'replace',
            'dry_run' => '1',
            'cities' => UploadedFile::fake()->createWithContent('cities5000.txt', (string) file_get_contents("{$dir}/cities.txt")),
            'admin1' => UploadedFile::fake()->createWithContent('admin1CodesASCII.txt', (string) file_get_contents("{$dir}/admin1CodesASCII.txt")),
            'countries' => UploadedFile::fake()->createWithContent('countryInfo.txt', (string) file_get_contents("{$dir}/countryInfo.txt")),
        ], ['Accept' => 'application/json'])->assertStatus(202)->assertJsonPath('data.pending.uploaded', true);

        $this->artisan('places:run-requested')->assertSuccessful();
        $this->assertSame(0, Place::query()->count(), 'a preview writes nothing');
        $this->getJson('/api/admin/places')
            ->assertJsonPath('data.status.dry_run', true)
            ->assertJsonPath('data.status.result.added', 9)
            ->assertJsonMissingPath('data.status.upload_dir');

        $this->postJson('/api/admin/places/import', ['source' => 'preview', 'mode' => 'replace'])->assertStatus(202);
        $this->artisan('places:run-requested')->assertSuccessful();

        $this->assertSame(9, Place::query()->count());
        Http::assertNothingSent();
        $this->assertSame([], File::directories(storage_path('app/private/geonames/uploads')));
    }

    public function test_uploads_need_all_three_files_and_known_types(): void
    {
        $this->actingAs($this->admin)->post('/api/admin/places/import', [
            'source' => 'upload',
            'mode' => 'update',
            'cities' => UploadedFile::fake()->create('cities.exe', 10),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cities', 'admin1', 'countries']);

        $this->postJson('/api/admin/places/import', ['source' => 'preview', 'mode' => 'update'])
            ->assertUnprocessable();
    }

    public function test_admin_cancels_a_queued_import(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/places/import', ['source' => 'download', 'mode' => 'update'])->assertStatus(202);

        $this->deleteJson('/api/admin/places/import')->assertOk()->assertJsonPath('data.pending', null);
        $this->artisan('places:run-requested')->assertSuccessful();

        $this->assertSame(0, Place::query()->count());
        $this->assertNull(app(PlaceImportRequests::class)->status());
    }

    public function test_a_failed_run_is_reported(): void
    {
        config(['places.base_url' => 'https://geonames.example.test/dump']);
        Http::fake(['geonames.example.test/*' => Http::response('', 503)]);

        $this->actingAs($this->admin)->postJson('/api/admin/places/import', ['source' => 'download', 'mode' => 'update'])->assertStatus(202);
        $this->artisan('places:run-requested')->assertFailed();

        $this->getJson('/api/admin/places')
            ->assertJsonPath('data.status.state', 'failed')
            ->assertJsonPath('data.status.error', 'Download failed (503): https://geonames.example.test/dump/countryInfo.txt');
    }

    public function test_the_scheduler_runs_requests_and_queues_the_optional_monthly_refresh(): void
    {
        $events = collect(app(Schedule::class)->events());
        $runner = $events->first(fn (Event $event): bool => str_contains((string) $event->command, 'places:run-requested'));
        $monthly = $events->first(fn (Event $event): bool => $event->description === 'places:monthly-refresh');
        $this->assertNotNull($runner);
        $this->assertNotNull($monthly);

        $this->assertFalse($runner->filtersPass($this->app));
        $this->assertFalse($monthly->filtersPass($this->app));

        app(PlaceImportRequests::class)->updateSettings('cities5000', true);
        $this->assertTrue($monthly->filtersPass($this->app));
        $monthly->run($this->app);

        $this->assertSame('schedule', app(PlaceImportRequests::class)->pending()['source']);
        $this->assertTrue($runner->filtersPass($this->app));
    }

    private function fakeGeoNames(string $dataset): void
    {
        $dir = base_path('tests/Fixtures/geonames');
        $zipPath = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        $zip->addFile("{$dir}/cities.txt", "{$dataset}.txt");
        $zip->close();
        $zipBody = (string) file_get_contents($zipPath);
        unlink($zipPath);

        config(['places.base_url' => 'https://geonames.example.test/dump']);
        Http::fake([
            'geonames.example.test/dump/countryInfo.txt' => Http::response((string) file_get_contents("{$dir}/countryInfo.txt")),
            'geonames.example.test/dump/admin1CodesASCII.txt' => Http::response((string) file_get_contents("{$dir}/admin1CodesASCII.txt")),
            "geonames.example.test/dump/{$dataset}.zip" => Http::response($zipBody),
        ]);
    }
}
