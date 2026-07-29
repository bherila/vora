<?php

namespace Tests\Feature\Media;

use App\Enums\ModerationStatus;
use App\Models\Character;
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
            'announce_on_approval' => true,
        ]);
        $this->assertSame([$interest->id], $user->fresh()->last_media_interest_ids);
    }

    public function test_store_allows_the_default_announcement_to_be_disabled(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->postJson('/api/media', [
            'type' => 'photo',
            'filename' => 'quiet.jpg',
            'content_type' => 'image/jpeg',
            'audience' => 'everyone',
            'announce' => false,
        ])->assertCreated();

        $this->assertFalse(Media::query()->sole()->announce_on_approval);
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

    public function test_delete_soft_deletes_media_and_hides_it_from_owner(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();
        $media = Media::factory()->for($user)->create();

        $this->actingAs($user)->deleteJson("/api/media/{$media->id}")->assertOk();
        $this->assertSoftDeleted('media', ['id' => $media->id]);

        $this->actingAs($user)->getJson('/api/media')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->actingAs($user)->getJson("/api/media/{$media->id}")->assertNotFound();
    }

    public function test_bulk_delete_soft_deletes_selected_media(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();
        $media = Media::factory()->for($user)->count(2)->create();

        $this->actingAs($user)->deleteJson('/api/media/bulk', [
            'media_ids' => $media->pluck('id')->all(),
        ])->assertOk()
            ->assertJsonPath('deleted_count', 2);

        foreach ($media as $item) {
            $this->assertSoftDeleted('media', ['id' => $item->id]);
        }
    }

    public function test_bulk_privacy_update_rejects_character_media(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();
        $character = $user->characters()->create(['display_name' => 'Nova']);
        $media = Media::factory()->for($user)->create(['character_id' => $character->id]);

        $this->actingAs($user)->patchJson('/api/media/bulk', [
            'media_ids' => [$media->id],
            'action' => 'set_privacy',
            'audience' => 'followers',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('media_ids');
    }

    public function test_bulk_assign_to_character_inherits_character_privacy(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();
        $character = $user->characters()->create([
            'display_name' => 'Nova',
            'audience' => 'followers',
            'discoverable' => false,
        ]);
        $media = Media::factory()->for($user)->create(['audience' => 'everyone', 'discoverable' => true]);

        $this->actingAs($user)->patchJson('/api/media/bulk', [
            'media_ids' => [$media->id],
            'action' => 'assign_character',
            'character_id' => $character->id,
        ])->assertOk()
            ->assertJsonPath('data.0.character_id', $character->id)
            ->assertJsonPath('data.0.audience', 'followers')
            ->assertJsonPath('data.0.discoverable', false);

        $fresh = $media->fresh();
        $this->assertSame($character->id, $fresh->character_id);
        $this->assertSame('followers', $fresh->audience->value);
        $this->assertFalse($fresh->discoverable);
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

    public function test_by_ulid_frames_item_with_uploader_profile_context(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create(['display_name' => 'Pat Uploader']);
        $viewer = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->unlisted()->approved()->create();

        // A non-owner sees the uploader frame (linking to the owner's profile)
        // and may report the item.
        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$media->ulid}")
            ->assertOk()
            ->assertJsonPath('data.owner.id', $owner->id)
            ->assertJsonPath('data.owner.display_name', 'Pat Uploader')
            ->assertJsonPath('data.owner.is_self', false)
            ->assertJsonPath('data.owner.href', "/users/{$owner->id}")
            ->assertJsonPath('data.can_report', true);

        // The owner sees their own frame (linking to /me) and cannot self-report.
        $this->actingAs($owner)->getJson("/api/media/by-ulid/{$media->ulid}")
            ->assertOk()
            ->assertJsonPath('data.owner.is_self', true)
            ->assertJsonPath('data.owner.href', '/me')
            ->assertJsonPath('data.can_report', false);
    }

    public function test_by_ulid_exposes_owner_editing_data_without_leaking_it_to_visitors(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $allowed = User::factory()->approved()->create();
        $persona = Character::factory()->for($owner)->create([
            'display_name' => 'Kira',
            'is_linked' => false,
        ]);
        $media = Media::factory()->for($owner)->approved()->create([
            'title' => 'Owner title',
            'character_id' => $persona->id,
            'audience' => 'specific',
            'discoverable' => false,
        ]);
        $media->syncAudienceMembers([$allowed->id]);

        $this->actingAs($owner)->getJson("/api/media/by-ulid/{$media->ulid}")
            ->assertOk()
            ->assertJsonPath('data.editable.title', 'Owner title')
            ->assertJsonPath('data.editable.character_id', $persona->id)
            ->assertJsonPath('data.editable.audience', 'specific')
            ->assertJsonPath('data.editable.audience_user_ids', [$allowed->id])
            ->assertJsonPath('data.editable.discoverable', false)
            ->assertJsonPath('data.editable.characters.0.id', $persona->id)
            ->assertJsonPath('data.editable.characters.0.display_name', 'Kira');

        $this->actingAs($allowed)->getJson("/api/media/by-ulid/{$media->ulid}")
            ->assertOk()
            ->assertJsonMissingPath('data.editable');
    }

    public function test_separate_persona_media_detail_uses_persona_attribution_and_visitor_shape(): void
    {
        $this->fakeStorage();
        User::factory()->create(); // ensure the owner is not the id-1 admin
        $owner = User::factory()->approved()->create(['display_name' => 'Sentinel Human']);
        $viewer = User::factory()->approved()->create();
        $persona = Character::factory()->for($owner)->create([
            'display_name' => 'Kira',
            'is_linked' => false,
        ]);
        $media = Media::factory()->for($owner)->unlisted()->approved()->create([
            'character_id' => $persona->id,
            'title' => null,
            'original_filename' => 'sentinel-private-name.jpg',
            'object_key' => "uploads/{$owner->id}/{$persona->ulid}.jpg",
        ]);

        $response = $this->actingAs($viewer)
            ->getJson("/api/media/by-ulid/{$media->ulid}")
            ->assertOk()
            ->assertJsonMissingPath('data.original_filename')
            ->assertJsonPath('data.owner.id', null)
            ->assertJsonPath('data.owner.display_name', 'Kira')
            ->assertJsonPath('data.owner.href', "/c/{$persona->ulid}")
            ->assertJsonPath('data.owner.is_self', false);

        $json = $response->getContent();
        $this->assertStringNotContainsString('Sentinel Human', $json);
        $this->assertStringNotContainsString('sentinel-private-name.jpg', $json);
        $this->assertStringNotContainsString("/users/{$owner->id}", $json);
        $this->assertStringNotContainsString("uploads/{$owner->id}", $json);
        $this->assertSame("/api/media/by-ulid/{$media->ulid}/asset/original", $response->json('data.url'));
    }

    public function test_linked_persona_media_detail_keeps_public_human_attribution(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create(['display_name' => 'Public Human']);
        $viewer = User::factory()->approved()->create();
        $persona = Character::factory()->for($owner)->create(['is_linked' => true]);
        $media = Media::factory()->for($owner)->approved()->create(['character_id' => $persona->id]);

        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$media->ulid}")
            ->assertOk()
            ->assertJsonPath('data.owner.id', $owner->id)
            ->assertJsonPath('data.owner.display_name', 'Public Human')
            ->assertJsonPath('data.owner.href', "/users/{$owner->id}");
    }

    public function test_soft_deleted_separate_persona_media_is_hidden_from_visitors(): void
    {
        $this->fakeStorage();
        User::factory()->create(); // ensure the owner is not the id-1 admin
        $owner = User::factory()->approved()->create(['display_name' => 'Sentinel Human']);
        $viewer = User::factory()->approved()->create();
        $persona = Character::factory()->for($owner)->create([
            'display_name' => 'Kira',
            'is_linked' => false,
        ]);
        $media = Media::factory()->for($owner)->approved()->create([
            'character_id' => $persona->id,
        ]);

        $persona->delete();

        $hidden = $this->actingAs($viewer)
            ->getJson("/api/media/by-ulid/{$media->ulid}")
            ->assertNotFound();
        $missing = $this->getJson('/api/media/by-ulid/01HZZZZZZZZZZZZZZZZZZZZZZZ')
            ->assertNotFound();

        $this->assertSame($missing->json('message'), $hidden->json('message'));
        $this->assertStringNotContainsString('Sentinel Human', $hidden->getContent());
        $this->get("/api/media/by-ulid/{$media->ulid}/asset/original")
            ->assertNotFound();

        $this->actingAs($owner)
            ->getJson("/api/media/by-ulid/{$media->ulid}")
            ->assertOk()
            ->assertJsonPath('data.owner.id', $owner->id);
    }

    public function test_owner_and_admin_media_detail_retain_original_filename(): void
    {
        $this->fakeStorage();
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create([
            'original_filename' => 'owner-library-name.jpg',
        ]);

        $this->actingAs($owner)->getJson("/api/media/by-ulid/{$media->ulid}")
            ->assertOk()
            ->assertJsonPath('data.original_filename', 'owner-library-name.jpg');

        $this->actingAs($admin)->getJson("/api/media/by-ulid/{$media->ulid}")
            ->assertOk()
            ->assertJsonPath('data.original_filename', 'owner-library-name.jpg');
    }

    public function test_visitor_asset_proxy_streams_visible_media_without_redirecting_to_storage(): void
    {
        $bytes = fopen('php://temp', 'r+');
        fwrite($bytes, 'safe-image-bytes');
        rewind($bytes);

        $this->mock(FileStorageService::class, function (MockInterface $mock) use ($bytes): void {
            $mock->shouldReceive('readStream')
                ->once()
                ->with('photos', 'uploads/reviewed/hidden-owner/photo.jpg')
                ->andReturn($bytes);
            $mock->shouldReceive('getFileSize')
                ->once()
                ->with('photos', 'uploads/reviewed/hidden-owner/photo.jpg')
                ->andReturn(16);
        });

        User::factory()->create(); // ensure the owner is not the id-1 admin
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create([
            'reviewed_object_key' => 'uploads/reviewed/hidden-owner/photo.jpg',
        ]);

        $response = $this->actingAs($viewer)
            ->get("/api/media/by-ulid/{$media->ulid}/asset/original")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Content-Disposition', 'inline')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame('safe-image-bytes', $response->streamedContent());
        $this->assertFalse($response->isRedirection());
    }

    public function test_visitor_asset_proxy_hides_unviewable_and_missing_media_identically(): void
    {
        User::factory()->create(); // ensure the owner is not the id-1 admin
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $hidden = Media::factory()->for($owner)->create();

        $existing = $this->actingAs($viewer)
            ->get("/api/media/by-ulid/{$hidden->ulid}/asset/original");
        $missing = $this->actingAs($viewer)
            ->get('/api/media/by-ulid/01HZZZZZZZZZZZZZZZZZZZZZZZ/asset/original');

        $existing->assertNotFound();
        $missing->assertNotFound();
        $this->assertSame($missing->getContent(), $existing->getContent());
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

    public function test_other_user_cannot_view_approved_media_by_id(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $video = Media::factory()->for($owner)->video()->approved()->create();

        $this->actingAs($viewer)->getJson("/api/media/{$video->id}")
            ->assertNotFound();
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

    public function test_owner_sees_under_review_flag_until_approved(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $pending = Media::factory()->for($owner)->create(['moderation_status' => ModerationStatus::Pending]);
        $approved = Media::factory()->for($owner)->approved()->create();

        // The owner is told their item isn't visible yet — but never the decision.
        $this->actingAs($owner)->getJson("/api/media/{$pending->id}")
            ->assertOk()
            ->assertJsonPath('data.under_review', true)
            ->assertJsonMissingPath('data.moderation_status');

        $this->actingAs($owner)->getJson("/api/media/{$approved->id}")
            ->assertOk()
            ->assertJsonPath('data.under_review', false);
    }

    public function test_admin_video_endpoint_exposes_original_signed_url_for_other_users_video(): void
    {
        $this->fakeStorage();
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        $video = Media::factory()->for($owner)->video()->approved()->create();

        $this->actingAs($admin)->getJson("/api/media/{$video->id}")
            ->assertOk()
            ->assertJsonPath('data.url', 'https://r2.example/view')
            ->assertJsonPath('data.video.status', 'processing');

        $this->actingAs($admin)->getJson("/api/media/by-ulid/{$video->ulid}")
            ->assertOk()
            ->assertJsonPath('data.url', 'https://r2.example/view')
            ->assertJsonPath('data.video.status', 'processing');
    }

    public function test_multipart_upload_can_init_presign_and_complete(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createMultipartUpload')
                ->once()
                ->with('s3', 'uploads/0/video.mp4', 'video/mp4')
                ->andReturn('upload-123');
            $mock->shouldReceive('getSignedMultipartUploadPartUrl')
                ->once()
                ->with('s3', 'uploads/0/video.mp4', 'upload-123', 1, 1024, 30)
                ->andReturn(['url' => 'https://r2.example/part/1', 'headers' => []]);
            $mock->shouldReceive('completeMultipartUpload')
                ->once()
                ->with('s3', 'uploads/0/video.mp4', 'upload-123', [['part_number' => 1, 'etag' => '"etag-1"']])
                ->andReturn(true);
            $mock->shouldReceive('fileExists')->with('s3', 'uploads/0/video.mp4')->andReturn(true);
            $mock->shouldReceive('getFileSize')->with('s3', 'uploads/0/video.mp4')->andReturn(1024);
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
            $mock->shouldReceive('get')->andReturn(null);
        });

        $user = User::factory()->approved()->create();
        $media = Media::factory()->for($user)->video()->pendingUpload()->create([
            'disk' => 's3',
            'object_key' => 'uploads/0/video.mp4',
            'mime_type' => 'video/mp4',
            'multipart_expected_size_bytes' => 1024,
        ]);

        $this->actingAs($user)->postJson("/api/media/{$media->id}/multipart/init")
            ->assertOk()
            ->assertJsonPath('data.upload_id', 'upload-123')
            ->assertJsonPath('data.part_size_bytes', 16 * 1024 * 1024)
            ->assertJsonPath('data.max_part_number', 1);

        $this->actingAs($user)->postJson("/api/media/{$media->id}/multipart/parts", [
            'upload_id' => 'upload-123',
            'part_numbers' => [1],
            'part_sizes' => [1 => 1024],
        ])->assertOk()
            ->assertJsonPath('data.0.part_number', 1)
            ->assertJsonPath('data.0.url', 'https://r2.example/part/1');

        $this->actingAs($user)->postJson("/api/media/{$media->id}/multipart/complete", [
            'upload_id' => 'upload-123',
            'parts' => [
                ['part_number' => 1, 'etag' => '"etag-1"'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.upload_status', 'ready');

        $fresh = $media->fresh();
        $this->assertSame('ready', $fresh->upload_status);
        $this->assertNull($fresh->multipart_upload_id);
        $this->assertSame(1024, $fresh->size_bytes);
    }

    public function test_multipart_part_presign_rejects_parts_beyond_declared_size(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createMultipartUpload')
                ->once()
                ->with('s3', 'uploads/0/video.mp4', 'video/mp4')
                ->andReturn('upload-123');
            $mock->shouldNotReceive('getSignedMultipartUploadPartUrl');
        });

        $user = User::factory()->approved()->create();
        $media = Media::factory()->for($user)->video()->pendingUpload()->create([
            'disk' => 's3',
            'object_key' => 'uploads/0/video.mp4',
            'mime_type' => 'video/mp4',
            'multipart_expected_size_bytes' => 1024,
        ]);

        $this->actingAs($user)->postJson("/api/media/{$media->id}/multipart/init")
            ->assertOk()
            ->assertJsonPath('data.max_part_number', 1);

        $this->actingAs($user)->postJson("/api/media/{$media->id}/multipart/parts", [
            'upload_id' => 'upload-123',
            'part_numbers' => [2],
            'part_sizes' => [2 => 1024],
        ])->assertNotFound();
    }

    public function test_multipart_upload_can_be_aborted(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('abortMultipartUpload')
                ->once()
                ->with('s3', 'uploads/0/video.mp4', 'upload-123')
                ->andReturn(true);
        });

        $user = User::factory()->approved()->create();
        $media = Media::factory()->for($user)->video()->pendingUpload()->create([
            'disk' => 's3',
            'object_key' => 'uploads/0/video.mp4',
            'multipart_upload_id' => 'upload-123',
            'multipart_part_size_bytes' => 16 * 1024 * 1024,
            'multipart_initiated_at' => now(),
        ]);

        $this->actingAs($user)->postJson("/api/media/{$media->id}/multipart/abort", [
            'upload_id' => 'upload-123',
        ])->assertOk();

        $this->assertNull($media->fresh()->multipart_upload_id);
    }

    public function test_media_from_a_disabled_owner_is_hidden_from_other_viewers(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();

        $approved = Media::factory()->for($owner)->approved()->create();

        // Visible before the owner is disabled...
        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$approved->ulid}")->assertOk();

        // The numeric-id route is never a public sharing surface.
        $this->actingAs($viewer)->getJson("/api/media/{$approved->id}")->assertNotFound();

        // ...and the shareable ULID route is hidden once the owner is
        // admin-disabled, matching the deactivated/deleted treatment
        // (and StoryPolicy).
        $owner->forceFill(['is_disabled' => true])->save();

        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$approved->ulid}")->assertNotFound();
        $this->actingAs($viewer)->getJson("/api/media/{$approved->id}")->assertNotFound();
    }

    public function test_probing_an_existing_hidden_media_id_is_indistinguishable_from_a_missing_one(): void
    {
        // Mirror production: with debug off the 404 body is just the message, so an
        // identical body is what actually closes the oracle (debug adds per-throw
        // trace fields that would otherwise differ between the two paths).
        config(['app.debug' => false]);

        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        // Pending media is never visible to a non-owner, so this is the "exists but
        // you may not see it" case.
        $hidden = Media::factory()->for($owner)->unlisted()->create(['moderation_status' => ModerationStatus::Pending]);

        // "Exists but hidden" and "doesn't exist" must answer identically — same
        // status AND same body — so neither numeric id nor ulid can be used to
        // enumerate other users' media.
        $existingById = $this->actingAs($viewer)->getJson("/api/media/{$hidden->id}");
        $missingById = $this->actingAs($viewer)->getJson('/api/media/99999999');

        $existingById->assertNotFound();
        $missingById->assertNotFound();
        $this->assertSame($missingById->getStatusCode(), $existingById->getStatusCode());
        $this->assertSame($missingById->getContent(), $existingById->getContent());

        $existingByUlid = $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$hidden->ulid}");
        $missingByUlid = $this->actingAs($viewer)->getJson('/api/media/by-ulid/01HZZZZZZZZZZZZZZZZZZZZZZZ');

        $existingByUlid->assertNotFound();
        $missingByUlid->assertNotFound();
        $this->assertSame($missingByUlid->getContent(), $existingByUlid->getContent());
    }
}
