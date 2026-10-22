<?php

namespace Tests\Feature\Account;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\Media;
use App\Models\User;
use App\Services\Media\AccountStorageUsage;
use App\Services\Media\MediaUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountStorageUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_account_reports_zero_usage(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->getJson('/api/account/storage')
            ->assertOk()->assertExactJson([
                'success' => true,
                'data' => [
                    'active_bytes' => 0,
                    'uploaded_bytes' => 0,
                    'saved_bytes' => 0,
                    'retained_bytes' => 0,
                    'total_bytes' => 0,
                    'active_file_count' => 0,
                    'retained_file_count' => 0,
                    'unknown_size_file_count' => 0,
                ],
            ]);
    }

    public function test_usage_includes_all_owned_completed_originals_without_revealing_review_state(): void
    {
        $user = User::factory()->approved()->create();
        $persona = Character::factory()->for($user)->create(['audience' => Audience::SpecificPeople]);
        Media::factory()->for($user)->approved()->create(['size_bytes' => 1000]);
        Media::factory()->for($user)->video()->rejected()->create([
            'character_id' => $persona->id,
            'size_bytes' => 5 * 1024 ** 3,
        ]);
        Media::factory()->for($user)->profilePicture()->create(['size_bytes' => 200]);
        Media::factory()->for($user)->create(['size_bytes' => 300, 'deleted_at' => now()]);
        Media::factory()->for($user)->pendingUpload()->create(['multipart_expected_size_bytes' => 9999]);
        Media::factory()->approved()->create(['size_bytes' => 8888]);
        $persona->delete();

        $this->actingAs($user)->getJson('/api/account/storage?user_id=999')
            ->assertOk()->assertExactJson([
                'success' => true,
                'data' => [
                    'active_bytes' => 5 * 1024 ** 3 + 1200,
                    'uploaded_bytes' => 5 * 1024 ** 3 + 1200,
                    'saved_bytes' => 0,
                    'retained_bytes' => 300,
                    'total_bytes' => 5 * 1024 ** 3 + 1500,
                    'active_file_count' => 3,
                    'retained_file_count' => 1,
                    'unknown_size_file_count' => 0,
                ],
            ]);
    }

    public function test_completion_and_deletion_lifecycle_updates_usage_without_counter_drift(): void
    {
        Storage::fake('photos');
        $user = User::factory()->approved()->create();
        $media = Media::factory()->for($user)->pendingUpload()->create(['disk' => 'photos']);
        Storage::disk('photos')->put($media->object_key, str_repeat('x', 1234));
        $usage = app(AccountStorageUsage::class);
        $uploads = app(MediaUploadService::class);

        $this->assertSame(0, $usage->forUser($user)['total_bytes']);
        $this->assertTrue($uploads->completeUpload($media));
        $this->assertTrue($uploads->completeUpload($media));
        $this->assertSame(1234, $usage->forUser($user)['active_bytes']);
        $this->assertSame(1, $usage->forUser($user)['active_file_count']);

        $media->delete();
        $this->assertSame(0, $usage->forUser($user)['active_bytes']);
        $this->assertSame(1234, $usage->forUser($user)['retained_bytes']);
        $this->assertSame(1234, $usage->forUser($user)['total_bytes']);

        $media->restore();
        $this->assertSame(1234, $usage->forUser($user)['active_bytes']);
        $this->assertSame(0, $usage->forUser($user)['retained_bytes']);

        $media->forceDelete();
        $this->assertSame(0, $usage->forUser($user)['total_bytes']);
    }

    public function test_unknown_sizes_are_reported_instead_of_implying_complete_totals(): void
    {
        $user = User::factory()->approved()->create();
        Media::factory()->for($user)->create(['size_bytes' => null]);
        Media::factory()->for($user)->create(['size_bytes' => null, 'deleted_at' => now()]);
        Media::factory()->for($user)->create(['size_bytes' => 100]);

        $this->actingAs($user)->getJson('/api/account/storage')->assertOk()
            ->assertJsonPath('data.total_bytes', 100)
            ->assertJsonPath('data.active_file_count', 2)
            ->assertJsonPath('data.retained_file_count', 1)
            ->assertJsonPath('data.unknown_size_file_count', 2);
    }

    public function test_account_settings_hydrates_the_same_usage_summary(): void
    {
        $user = User::factory()->approved()->create();
        Media::factory()->for($user)->create(['size_bytes' => 2048]);

        $this->actingAs($user)->get('/user/settings')->assertOk()
            ->assertViewHas('initialData', fn (array $data): bool => $data['userSettings']['storage_usage'] === app(AccountStorageUsage::class)->forUser($user));
    }

    public function test_guests_cannot_read_account_usage(): void
    {
        $this->getJson('/api/account/storage')->assertUnauthorized();
    }
}
