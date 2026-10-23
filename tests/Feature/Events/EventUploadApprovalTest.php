<?php

namespace Tests\Feature\Events;

use App\Enums\MediaPurpose;
use App\Enums\ModerationStatus;
use App\Models\Event;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\Media\MediaModerationService;
use App\Services\Media\MediaUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventUploadApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        config(['instance.modules.events' => true]);
        $this->admin = User::factory()->admin()->create(['id' => 100]);
        $this->event = Event::create(['name' => 'Wedding', 'visibility' => 'everyone', 'timezone' => 'UTC']);
        Notification::fake();
        Storage::fake('photos');
        Storage::fake('s3');
        Storage::fake('hls');
        $this->partialMock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://storage.example.test/view');
        });
    }

    private function uploader(): User
    {
        return User::factory()->approved()->create(['event_uploads_auto_approved' => true]);
    }

    private function upload(User $owner, bool $video = false, bool $thumbnail = false, MediaPurpose $purpose = MediaPurpose::Event): Media
    {
        $factory = Media::factory()->for($owner)->pendingUpload();
        $media = ($video ? $factory->video() : $factory)->create([
            'purpose' => $purpose, 'event_id' => $purpose === MediaPurpose::Event ? $this->event->id : null,
            'discoverable' => false, 'announce_on_approval' => false,
        ]);
        Storage::disk($media->disk)->put($media->object_key, 'original bytes');
        if ($thumbnail) {
            $media->thumbnail_key = 'thumbnails/'.$media->ulid.'.jpg';
            $media->save();
            Storage::disk('photos')->put($media->thumbnail_key, 'thumbnail bytes');
        }

        return $media;
    }

    public function test_flag_defaults_off_and_only_administrators_can_grant_or_remove_it(): void
    {
        $owner = User::factory()->approved()->create();
        $this->assertFalse($owner->fresh()->event_uploads_auto_approved);
        $this->actingAs($owner)->patchJson('/api/admin/users/'.$owner->id, ['event_uploads_auto_approved' => true])->assertForbidden();
        $this->getJson('/api/events/settings')->assertOk()->assertJsonPath('data.uploads_require_review', true);
        $this->patchJson('/api/account', ['event_uploads_auto_approved' => true])->assertOk();
        $this->assertFalse($owner->fresh()->event_uploads_auto_approved);
        $this->actingAs($this->admin)->getJson('/api/admin/users')->assertOk()
            ->assertJsonFragment(['id' => $owner->id, 'event_uploads_auto_approved' => false]);
        $this->patchJson('/api/admin/users/'.$owner->id, ['event_uploads_auto_approved' => 'yes'])->assertUnprocessable()->assertJsonValidationErrors('event_uploads_auto_approved');
        $this->patchJson('/api/admin/users/'.$owner->id, ['event_uploads_auto_approved' => true])->assertOk()->assertJsonPath('data.event_uploads_auto_approved', true);
        $this->actingAs($owner->fresh())->getJson('/api/events/settings')->assertOk()->assertJsonPath('data.uploads_require_review', false);
        $this->actingAs($this->admin);
        $this->patchJson('/api/admin/users/'.$owner->id, ['event_uploads_auto_approved' => false])->assertOk()->assertJsonPath('data.event_uploads_auto_approved', false);
    }

    public function test_registration_and_mass_assignment_cannot_self_grant_exemption(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Attendee', 'display_name' => 'Guest', 'email' => 'guest@example.test', 'birth_date' => '1990-01-01',
            'password' => 'password123', 'password_confirmation' => 'password123', 'event_uploads_auto_approved' => true,
        ])->assertOk();
        $owner = User::where('email', 'guest@example.test')->firstOrFail();
        $this->assertFalse($owner->event_uploads_auto_approved);
        $owner->fill(['event_uploads_auto_approved' => true])->save();
        $this->assertFalse($owner->fresh()->event_uploads_auto_approved);
    }

    public function test_event_uploads_still_need_review_by_default_despite_completion_payload(): void
    {
        $owner = User::factory()->approved()->create();
        $media = $this->upload($owner);
        $this->actingAs($owner)->postJson('/api/media/'.$media->id.'/complete', ['event_uploads_auto_approved' => true, 'moderation_status' => 'approved'])->assertOk();
        $this->assertTrue($media->fresh()->isReady());
        $this->assertSame(ModerationStatus::Pending, $media->fresh()->moderation_status);
        $this->assertNull($media->fresh()->reviewed_object_key);
    }

    public function test_exempt_event_photo_uses_immutable_reviewed_copies_and_system_attribution(): void
    {
        $owner = $this->uploader();
        $media = $this->upload($owner, thumbnail: true);
        $this->actingAs($owner)->postJson('/api/media/'.$media->id.'/complete')->assertOk();
        $media->refresh();
        $this->assertTrue($media->isApprovedContent());
        $this->assertNull($media->moderated_by_user_id);
        $this->assertNotNull($media->moderated_at);
        $this->assertStringContainsString('Automatically approved', $media->moderation_notes);
        $this->assertSame('original bytes', Storage::disk('photos')->get($media->reviewed_object_key));
        $this->assertSame('thumbnail bytes', Storage::disk('photos')->get($media->reviewed_thumbnail_key));
        Storage::disk('photos')->put($media->object_key, 'overwritten upload');
        $this->assertSame('original bytes', Storage::disk('photos')->get($media->reviewed_object_key));
        $viewer = User::factory()->approved()->create();
        $this->actingAs($viewer)->getJson('/api/events/media/'.$media->ulid)->assertOk();
    }

    public function test_automatic_video_approval_uses_the_configured_reviewed_prefix_for_hls(): void
    {
        config(['media.reviewed_key_prefix' => 'videos']);
        $media = $this->upload($this->uploader(), video: true);
        $this->assertTrue(app(MediaUploadService::class)->completeUpload($media));
        $media->refresh();
        $this->assertTrue($media->isApprovedContent());
        $this->assertSame('videos/'.$media->user_id.'/'.$media->ulid.'.mp4', $media->reviewed_object_key);
        $this->assertSame($media->reviewed_object_key, $media->playbackObjectKey());
        $this->assertSame('original bytes', Storage::disk('s3')->get($media->reviewed_object_key));
    }

    public function test_multipart_completion_uses_the_same_event_approval_path(): void
    {
        $media = $this->upload($this->uploader(), video: true);
        $media->forceFill(['multipart_upload_id' => 'multipart', 'multipart_part_size_bytes' => 16 * 1024 * 1024, 'multipart_expected_size_bytes' => 14, 'multipart_max_part_number' => 1, 'multipart_initiated_at' => now()])->save();
        $parts = [['part_number' => 1, 'etag' => 'etag']];
        $this->partialMock(FileStorageService::class, function ($mock) use ($media, $parts): void {
            $mock->shouldReceive('completeMultipartUpload')->once()->with('s3', $media->object_key, 'multipart', $parts)->andReturn(true);
        });
        $this->assertTrue(app(MediaUploadService::class)->completeMultipartUpload($media, 'multipart', $parts));
        $this->assertTrue($media->fresh()->isApprovedContent());
        $this->assertNull($media->fresh()->multipart_upload_id);
        $this->assertNull($media->fresh()->multipart_expected_size_bytes);
    }

    public function test_gallery_library_and_profile_uploads_are_not_exempt(): void
    {
        $owner = $this->uploader();
        foreach ([MediaPurpose::Gallery, MediaPurpose::Library, MediaPurpose::ProfilePicture] as $purpose) {
            $media = $this->upload($owner, purpose: $purpose);
            $this->assertTrue(app(MediaUploadService::class)->completeUpload($media));
            $this->assertSame(ModerationStatus::Pending, $media->fresh()->moderation_status);
            $this->assertNull($media->fresh()->reviewed_object_key);
        }
    }

    public function test_removing_exemption_affects_future_completions_and_never_reapproves_ready_media(): void
    {
        $owner = $this->uploader();
        $approved = $this->upload($owner);
        $this->assertTrue(app(MediaUploadService::class)->completeUpload($approved));
        $pending = $this->upload($owner);
        $this->actingAs($this->admin)->patchJson('/api/admin/users/'.$owner->id, ['event_uploads_auto_approved' => false])->assertOk();
        $this->assertTrue($approved->fresh()->isApprovedContent());
        $this->assertTrue(app(MediaUploadService::class)->completeUpload($pending));
        $this->assertTrue($pending->fresh()->isPendingReview());
        $this->patchJson('/api/admin/users/'.$owner->id, ['event_uploads_auto_approved' => true])->assertOk();
        $this->assertTrue(app(MediaUploadService::class)->completeUpload($pending));
        $this->assertTrue($pending->fresh()->isPendingReview());
        app(MediaModerationService::class)->reject($approved->refresh(), $this->admin);
        $this->assertTrue(app(MediaUploadService::class)->completeUpload($approved));
        $this->assertTrue($approved->fresh()->isRejected());
    }

    public function test_revoked_event_access_or_disabled_module_does_not_auto_publish(): void
    {
        $owner = $this->uploader();
        $media = $this->upload($owner);
        $this->event->update(['visibility' => 'specific']);
        $this->assertTrue(app(MediaUploadService::class)->completeUpload($media));
        $this->assertTrue($media->fresh()->isPendingReview());
        $this->event->members()->attach($owner);
        $media = $this->upload($owner);
        config(['instance.modules.events' => false]);
        $this->assertTrue(app(MediaUploadService::class)->completeUpload($media));
        $this->assertTrue($media->fresh()->isPendingReview());
    }

    public function test_false_copy_result_keeps_completed_upload_awaiting_review(): void
    {
        $media = $this->upload($this->uploader());
        $this->partialMock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('copyFile')->once()->andReturn(false);
        });
        $this->assertTrue(app(MediaUploadService::class)->completeUpload($media));
        $this->assertTrue($media->fresh()->isReady());
        $this->assertTrue($media->fresh()->isPendingReview());
        $this->assertNull($media->fresh()->reviewed_object_key);
    }

    public function test_copy_exception_keeps_completed_upload_awaiting_review(): void
    {
        Exceptions::fake();
        $media = $this->upload($this->uploader());
        $this->partialMock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('copyFile')->once()->andThrow(new \RuntimeException('Storage copy failed'));
        });
        $this->assertTrue(app(MediaUploadService::class)->completeUpload($media));
        $this->assertTrue($media->fresh()->isReady());
        $this->assertTrue($media->fresh()->isPendingReview());
        $this->assertNull($media->fresh()->reviewed_object_key);
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_thumbnail_copy_failure_does_not_publish_a_partially_prepared_original(): void
    {
        $media = $this->upload($this->uploader(), thumbnail: true);
        $reviewed = 'uploads/reviewed/'.$media->user_id.'/'.$media->ulid.'.jpg';
        $this->partialMock(FileStorageService::class, function ($mock) use ($media, $reviewed): void {
            $mock->shouldReceive('copyFile')->once()->with('photos', $media->object_key, 'photos', $reviewed, 'image/jpeg')->passthru();
            $mock->shouldReceive('copyFile')->once()->with('photos', $media->thumbnail_key, 'photos', 'uploads/reviewed-thumbnails/'.$media->user_id.'/'.$media->ulid.'.jpg', 'image/jpeg')->andReturn(false);
        });
        $this->assertTrue(app(MediaUploadService::class)->completeUpload($media));
        $this->assertTrue($media->fresh()->isPendingReview());
        $this->assertNull($media->fresh()->reviewed_object_key);
        $this->assertNull($media->fresh()->reviewed_thumbnail_key);
        Storage::disk('photos')->assertMissing($reviewed);
    }
}
