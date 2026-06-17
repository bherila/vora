<?php

namespace Tests\Feature\Media;

use App\Enums\ModerationStatus;
use App\Models\Interest;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class MediaApiTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStorage(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedUploadUrl')->andReturn([
                'url' => 'https://r2.example/put',
                'headers' => ['Content-Type' => 'image/jpeg'],
            ]);
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
            $mock->shouldReceive('get')->andReturn(null);
            $mock->shouldReceive('fileExists')->andReturn(true);
            $mock->shouldReceive('getFileSize')->andReturn(2048);
            $mock->shouldReceive('deleteFile')->andReturn(true);
        });
    }

    public function test_store_creates_pending_record_and_returns_upload_url(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Travel']);

        $response = $this->actingAs($user)->postJson('/api/media', [
            'type' => 'photo',
            'filename' => 'beach.jpg',
            'content_type' => 'image/jpeg',
            'title' => 'Beach',
            'audience' => 'everyone',
            'interest_ids' => [$interest->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('upload_url', 'https://r2.example/put')
            ->assertJsonPath('upload_headers.Content-Type', 'image/jpeg')
            ->assertJsonPath('data.upload_status', 'pending');

        // The owner response must never carry moderation state.
        $response->assertJsonMissingPath('data.moderation_status');
        $response->assertJsonMissingPath('data.moderation_notes');

        $this->assertDatabaseHas('media', [
            'user_id' => $user->id,
            'title' => 'Beach',
            'upload_status' => 'pending',
        ]);
        $this->assertSame([$interest->id], $user->fresh()->last_media_interest_ids);
    }

    public function test_store_rejects_mime_not_allowed_for_type(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->postJson('/api/media', [
            'type' => 'video',
            'filename' => 'x.jpg',
            'content_type' => 'image/jpeg',
            'audience' => 'everyone',
        ])->assertStatus(422)->assertJsonValidationErrors('content_type');
    }

    public function test_complete_marks_media_ready(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();
        $media = Media::factory()->for($user)->pendingUpload()->create(['disk' => 'photos']);

        $this->actingAs($user)->postJson("/api/media/{$media->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.upload_status', 'ready');

        $this->assertSame('ready', $media->fresh()->upload_status);
    }

    public function test_store_with_thumbnail_returns_second_upload_url_and_persists_hash(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->postJson('/api/media', [
            'type' => 'photo',
            'filename' => 'beach.jpg',
            'content_type' => 'image/jpeg',
            'audience' => 'everyone',
            'has_thumbnail' => true,
            'perceptual_hash' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        ])
            ->assertCreated()
            ->assertJsonPath('thumbnail_upload_url', 'https://r2.example/put')
            ->assertJsonPath('thumbnail_upload_headers.Content-Type', 'image/jpeg');

        $media = Media::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNotNull($media->thumbnail_key);
        $this->assertSame('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', $media->perceptual_hash);
    }

    public function test_store_without_thumbnail_returns_null_thumbnail_url(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->postJson('/api/media', [
            'type' => 'photo',
            'filename' => 'beach.jpg',
            'content_type' => 'image/jpeg',
            'audience' => 'everyone',
        ])
            ->assertCreated()
            ->assertJsonPath('thumbnail_upload_url', null);
    }

    public function test_complete_drops_thumbnail_key_when_object_missing(): void
    {
        // Original lands, thumbnail never does: completion should null the key
        // rather than fail the whole upload.
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fileExists')
                ->with('photos', 'uploads/0/orig.jpg')->andReturn(true);
            $mock->shouldReceive('getFileSize')
                ->with('photos', 'uploads/0/orig.jpg')->andReturn(2048);
            // The thumbnail object never landed: getFileSize returns null, as the
            // real adapter does for a missing key, so its key is dropped.
            $mock->shouldReceive('getFileSize')
                ->with('photos', 'uploads/thumbnails/0/missing.jpg')->andReturn(null);
            $mock->shouldReceive('deleteFile')
                ->with('photos', 'uploads/thumbnails/0/missing.jpg')->andReturn(true);
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
        });

        $user = User::factory()->approved()->create();
        $media = Media::factory()->for($user)->pendingUpload()->create([
            'disk' => 'photos',
            'object_key' => 'uploads/0/orig.jpg',
            'thumbnail_key' => 'uploads/thumbnails/0/missing.jpg',
        ]);

        $this->actingAs($user)->postJson("/api/media/{$media->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.upload_status', 'ready')
            ->assertJsonPath('data.thumbnail_url', null);

        $this->assertNull($media->fresh()->thumbnail_key);
    }

    public function test_index_lists_own_media_without_moderation_fields(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();
        Media::factory()->for($user)->rejected()->create();

        $response = $this->actingAs($user)->getJson('/api/media')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonMissingPath('data.0.moderation_status');
        $response->assertJsonMissingPath('data.0.moderation_notes');
    }

    public function test_index_is_paginated(): void
    {
        $this->fakeStorage();
        config(['media.page_size' => 2]);
        $user = User::factory()->approved()->create();
        Media::factory()->for($user)->count(3)->create();

        $this->actingAs($user)->getJson('/api/media')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.has_more', true);

        $this->actingAs($user)->getJson('/api/media?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_user_cannot_complete_or_delete_another_users_media(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $intruder = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->pendingUpload()->create();

        // 404 (not 403) so a non-owner can't use the id as an existence oracle.
        $this->actingAs($intruder)->postJson("/api/media/{$media->id}/complete")->assertNotFound();
        $this->actingAs($intruder)->deleteJson("/api/media/{$media->id}")->assertNotFound();
    }

    public function test_delete_removes_media(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();
        $media = Media::factory()->for($user)->create();

        $this->actingAs($user)->deleteJson("/api/media/{$media->id}")->assertOk();
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_other_user_can_view_approved_unlisted_by_ulid_but_not_pending(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();

        $approved = Media::factory()->for($owner)->unlisted()->approved()->create();
        $pending = Media::factory()->for($owner)->unlisted()->create(['moderation_status' => ModerationStatus::Pending]);

        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$approved->ulid}")
            ->assertOk()
            ->assertJsonMissingPath('data.moderation_status');

        // Pending media reads as not-found to non-owners (same as a bad ulid).
        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$pending->ulid}")->assertNotFound();
    }

    public function test_other_user_video_by_ulid_does_not_expose_original_signed_url(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $video = Media::factory()->for($owner)->video()->unlisted()->approved()->create();

        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$video->ulid}")
            ->assertOk()
            ->assertJsonPath('data.url', null)
            ->assertJsonPath('data.video.status', 'processing');
    }

    public function test_other_user_video_by_id_does_not_expose_original_signed_url(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $video = Media::factory()->for($owner)->video()->approved()->create();

        $this->actingAs($viewer)->getJson("/api/media/{$video->id}")
            ->assertOk()
            ->assertJsonPath('data.url', null)
            ->assertJsonPath('data.video.status', 'processing');
    }

    public function test_owner_video_endpoint_still_includes_original_signed_url(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $video = Media::factory()->for($owner)->video()->create();

        $this->actingAs($owner)->getJson("/api/media/{$video->id}")
            ->assertOk()
            ->assertJsonPath('data.url', 'https://r2.example/view');
    }

    public function test_media_from_a_disabled_owner_is_hidden_from_other_viewers(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();

        $approved = Media::factory()->for($owner)->approved()->create();

        // Visible before the owner is disabled...
        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$approved->ulid}")->assertOk();

        // ...and hidden on every path once the owner is admin-disabled, matching
        // the deactivated/deleted treatment (and StoryPolicy).
        $owner->forceFill(['is_disabled' => true])->save();

        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$approved->ulid}")->assertNotFound();
        $this->actingAs($viewer)->getJson("/api/media/{$approved->id}")->assertNotFound();
    }

    public function test_probing_an_existing_hidden_media_id_is_indistinguishable_from_a_missing_one(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        // Pending media is never visible to a non-owner, so this is the "exists but
        // you may not see it" case.
        $hidden = Media::factory()->for($owner)->create(['moderation_status' => ModerationStatus::Pending]);

        // "Exists but hidden" and "doesn't exist" must answer identically so the
        // numeric id can't be used to enumerate other users' media.
        $existing = $this->actingAs($viewer)->getJson("/api/media/{$hidden->id}");
        $missing = $this->actingAs($viewer)->getJson('/api/media/99999999');

        $existing->assertNotFound();
        $missing->assertNotFound();
        $this->assertSame($missing->getStatusCode(), $existing->getStatusCode());
    }
}
