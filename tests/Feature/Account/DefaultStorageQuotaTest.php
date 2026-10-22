<?php

namespace Tests\Feature\Account;

use App\Enums\Audience;
use App\Enums\MediaType;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\Media\AccountStorageUsage;
use App\Services\Media\MediaUploadService;
use App\Services\SettingsService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DefaultStorageQuotaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->approved()->create(['is_admin' => true]);
    }

    private function account(?int $limit = null, bool $unlimited = false): User
    {
        return User::factory()->approved()->create([
            'library_enabled' => true, 'storage_limit_bytes' => $limit, 'storage_unlimited' => $unlimited,
        ]);
    }

    private function setDefault(?int $bytes): void
    {
        $this->actingAs($this->admin)->putJson('/api/admin/storage-settings', ['default_storage_limit_bytes' => $bytes])
            ->assertOk()->assertJsonPath('data.default_storage_limit_bytes', $bytes);
    }

    private function usage(User $user): array
    {
        return app(AccountStorageUsage::class)->forUser($user->fresh());
    }

    private function reserve(User $user, int $bytes): Media
    {
        return app(MediaUploadService::class)->createPendingUpload(
            $user, MediaType::Photo, 'photo.jpg', 'image/jpeg', null, Audience::SpecificPeople, [], expectedSizeBytes: $bytes,
        )['media'];
    }

    public function test_initial_default_remains_unlimited_and_existing_and_future_accounts_inherit_changes(): void
    {
        $existing = $this->account();
        $this->assertNull(app(SettingsService::class)->defaultStorageLimitBytes());
        $this->assertNull($this->usage($existing)['limit_bytes']);
        $this->setDefault(100);
        $future = $this->account();
        foreach ([$existing, $future] as $user) {
            $this->assertSame(100, $this->usage($user)['limit_bytes']);
            $this->assertNull($user->fresh()->storage_limit_bytes);
            $this->assertFalse($user->fresh()->storage_unlimited);
        }
        $this->setDefault(25);
        $this->assertSame(25, $this->usage($existing)['remaining_bytes']);
        $this->assertSame(25, $this->usage($future)['limit_bytes']);
        $this->setDefault(null);
        $this->assertNull($this->usage($existing)['limit_bytes']);
    }

    public function test_admin_listing_presents_the_default_and_each_effective_policy_without_per_user_settings_reads(): void
    {
        $inherited = $this->account();
        $custom = $this->account(70);
        $unlimited = $this->account(null, true);
        $zero = $this->account(0);
        User::factory()->approved()->count(8)->create();
        foreach ([null, 100] as $default) {
            $this->setDefault($default);
            Cache::forget('setting:'.SettingsService::DEFAULT_STORAGE_LIMIT_BYTES);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->actingAs($this->admin)->getJson('/api/admin/users')->assertOk()
                ->assertJsonPath('settings.default_storage_limit_bytes', $default);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $settingReads = array_filter($queries, fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
                && in_array(SettingsService::DEFAULT_STORAGE_LIMIT_BYTES, $query['bindings'], true));
            $this->assertCount(1, $settingReads);
            $users = collect($response->json('data'))->keyBy('id');
            $this->assertSame($default, $users[$inherited->id]['effective_storage_limit_bytes']);
            $this->assertFalse($users[$inherited->id]['storage_unlimited']);
            $this->assertSame(70, $users[$custom->id]['storage_limit_bytes']);
            $this->assertSame(70, $users[$custom->id]['effective_storage_limit_bytes']);
            $this->assertSame(0, $users[$zero->id]['effective_storage_limit_bytes']);
            $this->assertTrue($users[$unlimited->id]['storage_unlimited']);
            $this->assertNull($users[$unlimited->id]['effective_storage_limit_bytes']);
        }
    }

    public function test_custom_and_explicit_unlimited_overrides_survive_default_changes(): void
    {
        $custom = $this->account(70);
        $unlimited = $this->account(null, true);
        foreach ([100, 0, null] as $default) {
            $this->setDefault($default);
            $this->assertSame(70, $this->usage($custom)['limit_bytes']);
            $this->assertNull($this->usage($unlimited)['limit_bytes']);
            $this->assertNull($this->usage($unlimited)['remaining_bytes']);
        }
    }

    public function test_account_updates_can_choose_custom_default_and_explicit_unlimited_without_stale_flags(): void
    {
        $this->setDefault(100);
        $user = $this->account(70);
        $path = '/api/admin/users/'.$user->id;
        $this->patchJson($path, ['storage_unlimited' => true])->assertOk()
            ->assertJsonPath('data.storage_limit_bytes', null)->assertJsonPath('data.storage_unlimited', true)
            ->assertJsonPath('data.effective_storage_limit_bytes', null);
        $this->assertNull($this->usage($user)['limit_bytes']);
        $this->patchJson($path, ['storage_limit_bytes' => 30])->assertOk()
            ->assertJsonPath('data.storage_unlimited', false)->assertJsonPath('data.effective_storage_limit_bytes', 30);
        $this->patchJson($path, ['storage_limit_bytes' => null, 'storage_unlimited' => true])->assertOk()
            ->assertJsonPath('data.storage_unlimited', true);
        $this->patchJson($path, ['storage_limit_bytes' => null])->assertOk()
            ->assertJsonPath('data.storage_unlimited', false)->assertJsonPath('data.effective_storage_limit_bytes', 100);
        $this->patchJson($path, ['storage_limit_bytes' => 0, 'storage_unlimited' => false])->assertOk()
            ->assertJsonPath('data.effective_storage_limit_bytes', 0);
        $this->assertSame(0, $this->usage($user)['limit_bytes']);
    }

    public function test_conflicting_limits_and_invalid_default_values_are_rejected_without_mutating_policy(): void
    {
        $this->setDefault(100);
        $user = $this->account(70);
        $this->patchJson('/api/admin/users/'.$user->id, ['storage_limit_bytes' => 10, 'storage_unlimited' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('storage_limit_bytes');
        $this->assertSame(70, $user->fresh()->storage_limit_bytes);
        $this->assertFalse($user->fresh()->storage_unlimited);
        $this->putJson('/api/admin/storage-settings', [])->assertUnprocessable()->assertJsonValidationErrors('default_storage_limit_bytes');
        foreach ([-1, 1.5, 'unlimited', 9007199254740992, []] as $value) {
            $this->putJson('/api/admin/storage-settings', ['default_storage_limit_bytes' => $value])
                ->assertUnprocessable()->assertJsonValidationErrors('default_storage_limit_bytes');
        }
        $this->patchJson('/api/admin/users/'.$user->id, ['storage_unlimited' => 'unlimited'])
            ->assertUnprocessable()->assertJsonValidationErrors('storage_unlimited');
        $this->assertSame(100, app(SettingsService::class)->defaultStorageLimitBytes());
        $this->setDefault(SettingsService::MAX_STORAGE_LIMIT_BYTES);
        $this->setDefault(0);
    }

    public function test_global_settings_and_unlimited_overrides_are_admin_only(): void
    {
        $user = $this->account();
        $this->putJson('/api/admin/storage-settings', ['default_storage_limit_bytes' => 0])->assertUnauthorized();
        $this->actingAs($user)->putJson('/api/admin/storage-settings', ['default_storage_limit_bytes' => 0])->assertForbidden();
        $this->patchJson('/api/admin/users/'.$user->id, ['storage_unlimited' => true])->assertForbidden();
        $this->admin->forceFill(['is_disabled' => true])->save();
        $this->actingAs($this->admin)->putJson('/api/admin/storage-settings', ['default_storage_limit_bytes' => 0])->assertForbidden();
        $this->assertNull(app(SettingsService::class)->defaultStorageLimitBytes());
        $this->assertFalse($user->fresh()->storage_unlimited);
    }

    public function test_inherited_upload_reservations_enforce_the_default_and_explicit_unlimited_bypasses_zero(): void
    {
        $this->partialMock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getSignedUploadUrl')->andReturn(['url' => 'https://example.test/upload', 'headers' => []]);
        });
        $user = $this->account();
        $unlimited = $this->account(null, true);
        $this->setDefault(10);
        $this->reserve($user, 6);
        $this->assertSame(4, $this->usage($user)['remaining_bytes']);
        try {
            $this->reserve($user, 5);
            $this->fail('An inherited quota must limit reservations.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('storage', $exception->errors());
        }
        $this->setDefault(0);
        $this->reserve($unlimited, 20);
        $this->assertSame(20, $this->usage($unlimited)['reserved_bytes']);
        $this->assertSame(6, $this->usage($user)['used_bytes']);
        $this->assertTrue($this->usage($user)['over_limit']);
        $this->assertSame('limit_reached', $this->usage($user)['warning']);
    }

    public function test_pending_completion_uses_the_current_default_and_preserves_explicit_unlimited(): void
    {
        Storage::fake('photos');
        $inherited = $this->account();
        $unlimited = $this->account(null, true);
        $this->setDefault(10);
        $pending = Media::factory()->for($inherited)->pendingUpload()->create(['disk' => 'photos', 'multipart_expected_size_bytes' => 8]);
        $exempt = Media::factory()->for($unlimited)->pendingUpload()->create(['disk' => 'photos', 'multipart_expected_size_bytes' => 8]);
        foreach ([$pending, $exempt] as $media) {
            Storage::disk('photos')->put($media->object_key, '12345678');
        }
        $this->setDefault(0);
        $this->assertFalse(app(MediaUploadService::class)->completeUpload($pending));
        $this->assertModelMissing($pending);
        Storage::disk('photos')->assertMissing($pending->object_key);
        $this->assertTrue(app(MediaUploadService::class)->completeUpload($exempt));
        $this->assertSame(0, $this->usage($inherited)['reserved_bytes']);
        $this->assertSame(8, $this->usage($unlimited)['active_bytes']);
    }

    public function test_completion_checks_actual_size_against_inherited_allowance(): void
    {
        Storage::fake('photos');
        $user = $this->account();
        $this->setDefault(10);
        Media::factory()->for($user)->create(['size_bytes' => 4]);
        $pending = Media::factory()->for($user)->pendingUpload()->create(['disk' => 'photos', 'multipart_expected_size_bytes' => 6]);
        Storage::disk('photos')->put($pending->object_key, '1234567');
        $this->assertFalse(app(MediaUploadService::class)->completeUpload($pending));
        $this->assertSame(4, $this->usage($user)['used_bytes']);
    }

    public function test_saves_use_inherited_limits_and_remain_idempotent_and_removable_after_default_is_lowered(): void
    {
        $owner = $this->account(null, true);
        $user = $this->account();
        $unlimited = $this->account(null, true);
        $media = Media::factory()->for($owner)->approved()->create(['size_bytes' => 8]);
        $extra = Media::factory()->for($owner)->approved()->create(['size_bytes' => 3]);
        $this->setDefault(10);
        $ulid = $this->actingAs($user)->postJson('/api/library/save', ['media_ulid' => $media->ulid])
            ->assertCreated()->json('data.ulid');
        $this->postJson('/api/library/save', ['media_ulid' => $extra->ulid])->assertUnprocessable()->assertJsonValidationErrors('storage');
        $this->setDefault(0);
        $this->actingAs($user)->getJson('/api/library/items/'.$ulid)->assertOk();
        $this->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertCreated()->assertJsonPath('data.ulid', $ulid);
        $this->deleteJson('/api/library/items/'.$ulid)->assertOk();
        $this->assertSame(0, $this->usage($user)['used_bytes']);
        $this->actingAs($unlimited)->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertCreated();
        $this->assertSame(8, $this->usage($unlimited)['saved_bytes']);
    }

    public function test_registration_and_profile_input_cannot_opt_out_of_a_finite_default(): void
    {
        Event::fake([Registered::class]);
        $this->setDefault(10);
        $this->app['auth']->logout();
        $this->post('/register', [
            'name' => 'Default storage account', 'display_name' => 'Default storage', 'email' => 'default-storage@example.test',
            'birth_date' => today()->subYears(21)->toDateString(), 'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'storage_unlimited' => true, 'storage_limit_bytes' => 999,
        ])->assertRedirect();
        $registered = User::where('email', 'default-storage@example.test')->firstOrFail();
        $this->assertFalse($registered->storage_unlimited);
        $this->assertNull($registered->storage_limit_bytes);
        $this->assertSame(10, $this->usage($registered)['limit_bytes']);
        $ordinary = $this->account();
        $this->actingAs($ordinary)->patchJson('/api/account', ['storage_unlimited' => true, 'storage_limit_bytes' => 999])->assertOk();
        $this->assertFalse($ordinary->fresh()->storage_unlimited);
        $this->assertNull($ordinary->fresh()->storage_limit_bytes);
        $ordinary->fill(['storage_unlimited' => true, 'storage_limit_bytes' => 999])->save();
        $this->assertFalse($ordinary->fresh()->storage_unlimited);
        $this->assertSame(10, $this->usage($ordinary)['limit_bytes']);
    }
}
