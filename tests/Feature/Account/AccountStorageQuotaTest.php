<?php

namespace Tests\Feature\Account;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Enums\MediaType;
use App\Models\LibraryItem;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\Media\AccountStorageUsage;
use App\Services\Media\MediaUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

class AccountStorageQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function account(?int $limit = 100): User
    {
        return User::factory()->approved()->create(['library_enabled' => true, 'storage_limit_bytes' => $limit]);
    }

    private function usage(User $user): array
    {
        return app(AccountStorageUsage::class)->forUser($user->fresh());
    }

    private function reserve(User $user, int $size, MediaPurpose $purpose = MediaPurpose::Gallery): Media
    {
        return app(MediaUploadService::class)->createPendingUpload(
            $user, MediaType::Photo, 'photo.jpg', 'image/jpeg', null, Audience::SpecificPeople, [],
            purpose: $purpose, expectedSizeBytes: $size,
        )['media'];
    }

    private function fakeSigning(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedUploadUrl')->andReturn(['url' => 'https://example.test/upload', 'headers' => []]);
        });
    }

    public function test_pending_uploads_reserve_space_across_all_upload_purposes(): void
    {
        $this->fakeSigning();
        $user = $this->account();
        $this->reserve($user, 60, MediaPurpose::ProfilePicture);
        $this->reserve($user, 30, MediaPurpose::Library);
        $usage = $this->usage($user);
        $this->assertSame(90, $usage['reserved_bytes']);
        $this->assertSame(10, $usage['remaining_bytes']);
        $this->assertSame('near_limit', $usage['warning']);
        try {
            $this->reserve($user, 11);
            $this->fail('Reservations must prevent oversubscription.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('storage', $exception->errors());
        }
        $this->assertSame(2, Media::query()->count());
        $this->assertSame(90, $this->usage($user)['used_bytes']);
    }

    public function test_completion_replaces_its_reservation_with_actual_usage_exactly_once(): void
    {
        Storage::fake('photos');
        $user = $this->account();
        $media = Media::factory()->for($user)->pendingUpload()->create(['disk' => 'photos', 'multipart_expected_size_bytes' => 60]);
        Media::factory()->for($user)->pendingUpload()->create(['multipart_expected_size_bytes' => 40]);
        Storage::disk('photos')->put($media->object_key, str_repeat('x', 60));
        $uploads = app(MediaUploadService::class);
        $this->assertTrue($uploads->completeUpload($media));
        $this->assertTrue($uploads->completeUpload($media));
        $usage = $this->usage($user);
        $this->assertSame(100, $usage['used_bytes']);
        $this->assertSame(60, $usage['active_bytes']);
        $this->assertSame(40, $usage['reserved_bytes']);
        $this->assertFalse($usage['over_limit']);
        $this->assertSame('limit_reached', $usage['warning']);
    }

    public function test_completion_rejects_actual_size_above_quota_and_releases_reservation(): void
    {
        Storage::fake('photos');
        $user = $this->account();
        $media = Media::factory()->for($user)->pendingUpload()->create(['disk' => 'photos', 'multipart_expected_size_bytes' => 60]);
        $user->libraryItems()->create(['media_id' => $media->id, 'source' => 'upload']);
        Storage::disk('photos')->put($media->object_key, str_repeat('x', 101));
        $this->assertFalse(app(MediaUploadService::class)->completeUpload($media));
        $this->assertModelMissing($media);
        $this->assertSame(0, LibraryItem::query()->count());
        Storage::disk('photos')->assertMissing($media->object_key);
        $this->assertSame(0, $this->usage($user)['used_bytes']);
    }

    public function test_finite_quota_fails_closed_when_actual_object_size_is_unknown(): void
    {
        $user = $this->account();
        $media = Media::factory()->for($user)->pendingUpload()->create(['multipart_expected_size_bytes' => 60]);
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fileExists')->andReturn(true);
            $mock->shouldReceive('getFileSize')->andReturn(null);
            $mock->shouldReceive('deleteFile')->once()->andReturn(true);
        });
        $this->assertFalse(app(MediaUploadService::class)->completeUpload($media));
        $this->assertModelMissing($media);
    }

    public function test_quota_changes_are_applied_to_already_pending_uploads(): void
    {
        Storage::fake('photos');
        $user = $this->account(null);
        $media = Media::factory()->for($user)->pendingUpload()->create(['disk' => 'photos', 'multipart_expected_size_bytes' => 60]);
        Storage::disk('photos')->put($media->object_key, str_repeat('x', 60));
        $user->forceFill(['storage_limit_bytes' => 0])->save();
        $this->assertFalse(app(MediaUploadService::class)->completeUpload($media));
        $this->assertSame(0, $this->usage($user)['reserved_bytes']);
    }

    public function test_abort_and_stale_cleanup_release_reservations(): void
    {
        Storage::fake('photos');
        $user = $this->account();
        $media = Media::factory()->for($user)->video()->pendingUpload()->create([
            'multipart_upload_id' => 'upload-123', 'multipart_expected_size_bytes' => 60,
        ]);
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('abortMultipartUpload')->once()->andReturn(true);
            $mock->shouldReceive('fileExists')->andReturn(false);
        });
        $this->assertTrue(app(MediaUploadService::class)->abortMultipartUpload($media, 'upload-123'));
        $this->assertSame(0, $this->usage($user)['reserved_bytes']);
        Media::factory()->for($user)->pendingUpload()->create([
            'multipart_expected_size_bytes' => 90, 'created_at' => now()->subHours(25),
        ]);
        $this->assertSame(90, $this->usage($user)['reserved_bytes']);
        $this->artisan('media:prune-orphans')->assertSuccessful();
        $this->assertSame(0, $this->usage($user)['reserved_bytes']);
    }

    public function test_saves_are_idempotent_and_no_extra_charge_applies_to_own_active_upload(): void
    {
        $user = $this->account(8);
        $media = Media::factory()->approved()->create(['size_bytes' => 8]);
        $payload = ['media_ulid' => $media->ulid];
        $ulid = $this->actingAs($user)->postJson('/api/library/save', $payload)->assertCreated()->json('data.ulid');
        $this->postJson('/api/library/save', $payload)->assertCreated()->assertJsonPath('data.ulid', $ulid);
        $this->assertSame(8, $this->usage($user)['saved_bytes']);
        $other = Media::factory()->approved()->create(['size_bytes' => 1]);
        $this->postJson('/api/library/save', ['media_ulid' => $other->ulid])->assertUnprocessable()->assertJsonValidationErrors('storage');
        $this->assertSame(1, $user->libraryItems()->count());
        $this->deleteJson('/api/library/items/'.$ulid)->assertOk();
        $own = Media::factory()->for($user)->approved()->create(['size_bytes' => 8]);
        $this->postJson('/api/library/save', ['media_ulid' => $own->ulid])->assertCreated();
        $this->assertSame(8, $this->usage($user)['used_bytes']);
    }

    public function test_unknown_active_original_blocks_growth_but_unknown_retained_original_does_not(): void
    {
        $this->fakeSigning();
        $user = $this->account();
        $unknown = Media::factory()->for($user)->create(['size_bytes' => null]);
        $this->assertSame('unknown_size', $this->usage($user)['warning']);
        $this->assertTrue($this->usage($user)['over_limit']);
        $media = Media::factory()->approved()->create(['size_bytes' => 1]);
        $this->actingAs($user)->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertUnprocessable();
        $unknown->delete();
        $this->reserve($user, 100);
        $this->assertSame(100, $this->usage($user)['reserved_bytes']);
        $this->assertSame(0, $this->usage($user)['active_unknown_size_file_count']);
    }

    public function test_unknown_saved_file_is_rejected_under_finite_cap_but_unlimited_default_allows_it(): void
    {
        $media = Media::factory()->approved()->create(['size_bytes' => null]);
        $limited = $this->account();
        $this->actingAs($limited)->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertUnprocessable();
        $unlimited = $this->account(null);
        $this->actingAs($unlimited)->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertCreated();
        $this->assertNull($this->usage($unlimited)['limit_bytes']);
        $this->assertNull($this->usage($unlimited)['remaining_bytes']);
        $this->assertFalse($this->usage($unlimited)['over_limit']);
    }

    public function test_removing_private_upload_frees_allowance_while_retained_bytes_remain_visible(): void
    {
        $user = $this->account(8);
        $media = Media::factory()->for($user)->approved()->create(['size_bytes' => 8, 'purpose' => MediaPurpose::Library]);
        $item = $user->libraryItems()->create(['media_id' => $media->id, 'source' => 'upload']);
        $user->forceFill(['storage_limit_bytes' => 0, 'library_enabled' => false])->save();
        $this->actingAs($user)->getJson('/api/library/items/'.$item->ulid)->assertOk();
        $this->deleteJson('/api/library/items/'.$item->ulid)->assertOk();
        $usage = $this->usage($user);
        $this->assertSame(0, $usage['used_bytes']);
        $this->assertSame(8, $usage['retained_bytes']);
        $this->assertSame(8, $usage['total_bytes']);
        $this->assertFalse($usage['over_limit']);
    }

    public function test_notes_and_view_only_shares_consume_no_quota_even_with_zero_limit(): void
    {
        $owner = $this->account();
        $recipient = $this->account(0);
        $item = $owner->libraryItems()->create(['source' => 'note', 'body' => 'Note']);
        $item->members()->attach($recipient);
        $this->actingAs($recipient)->postJson('/api/library/save', ['item_ulid' => $item->ulid])->assertCreated();
        $this->postJson('/api/library/notes', ['title' => 'Private', 'body' => 'Text'])->assertCreated();
        $this->assertSame(0, $this->usage($recipient)['used_bytes']);
    }

    public function test_only_admin_can_set_or_clear_valid_storage_limits(): void
    {
        $admin = User::factory()->approved()->create(['is_admin' => true]);
        $user = $this->account();
        $this->actingAs($user)->patchJson('/api/admin/users/'.$user->id, ['storage_limit_bytes' => null])->assertForbidden();
        $this->actingAs($admin)->patchJson('/api/admin/users/'.$user->id, ['storage_limit_bytes' => 0])->assertOk()->assertJsonPath('data.storage_limit_bytes', 0);
        foreach ([-1, 1.5, 'unlimited', 9007199254740992] as $limit) {
            $this->patchJson('/api/admin/users/'.$user->id, ['storage_limit_bytes' => $limit])->assertUnprocessable()->assertJsonValidationErrors('storage_limit_bytes');
        }
        $this->patchJson('/api/admin/users/'.$user->id, ['storage_limit_bytes' => null])->assertOk()->assertJsonPath('data.storage_limit_bytes', null);
        $this->assertNull($user->fresh()->storage_limit_bytes);
    }

    public function test_cancel_releases_reservation_and_blocks_late_completion_without_purging_late_objects(): void
    {
        Storage::fake('photos');
        $user = $this->account(8);
        $media = Media::factory()->for($user)->pendingUpload()->create([
            'purpose' => MediaPurpose::Library, 'disk' => 'photos', 'multipart_expected_size_bytes' => 8,
        ]);
        $user->libraryItems()->create(['media_id' => $media->id, 'source' => 'upload']);
        $user->forceFill(['library_enabled' => false])->save();
        $this->actingAs($user)->postJson('/api/media/'.$media->id.'/cancel-upload')->assertOk();
        $this->assertSoftDeleted($media);
        $this->assertSame(0, $this->usage($user)['reserved_bytes']);
        $this->assertSame(0, $user->libraryItems()->count());
        Storage::disk('photos')->put($media->object_key, 'late PUT');
        $this->postJson('/api/media/'.$media->id.'/complete')->assertNotFound();
        $this->assertFalse(app(MediaUploadService::class)->completeUpload($media));
        Storage::disk('photos')->assertExists($media->object_key);
        $media->created_at = now()->subHours(25);
        $media->save();
        $this->artisan('media:prune-orphans')->assertSuccessful();
        Storage::disk('photos')->assertMissing($media->object_key);
    }

    public function test_cancel_is_owner_only_refuses_ready_rows_and_aborts_pending_multipart(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('abortMultipartUpload')->once()->andReturn(true);
        });
        $this->account(); // Reserve primary admin id; the stranger is ordinary.
        $user = $this->account();
        $stranger = $this->account();
        $ready = Media::factory()->for($user)->create(['size_bytes' => 8]);
        $pending = Media::factory()->for($user)->pendingUpload()->create([
            'multipart_upload_id' => 'upload-123', 'multipart_expected_size_bytes' => 8,
        ]);
        $this->actingAs($stranger)->postJson('/api/media/'.$pending->id.'/cancel-upload')->assertNotFound();
        $this->actingAs($user)->postJson('/api/media/'.$ready->id.'/cancel-upload')->assertConflict();
        $this->assertNotSoftDeleted($ready);
        $this->postJson('/api/media/'.$pending->id.'/cancel-upload')->assertOk();
        $this->assertSame(0, $this->usage($user)['reserved_bytes']);
        $this->assertSoftDeleted($pending);
    }

    public function test_signing_failure_does_not_leave_a_quota_reservation(): void
    {
        $user = $this->account();
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedUploadUrl')->andThrow(new \RuntimeException('Signing unavailable'));
        });
        try {
            $this->reserve($user, 100);
            $this->fail('Signing should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Signing unavailable', $exception->getMessage());
        }
        $this->assertSame(0, Media::query()->count());
        $this->assertSame(0, $this->usage($user)['reserved_bytes']);
    }
}
