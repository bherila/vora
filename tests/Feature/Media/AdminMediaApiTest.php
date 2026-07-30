<?php

namespace Tests\Feature\Media;

use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class AdminMediaApiTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStorage(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
            $mock->shouldReceive('getSignedDownloadUrl')->andReturn('https://r2.example/download');
            $mock->shouldReceive('get')->andReturn(null);
            $mock->shouldReceive('fileExists')->andReturn(true);
            $mock->shouldReceive('copyFile')->andReturn(true);
            $mock->shouldReceive('deleteFile')->andReturn(true);
        });
    }

    public function test_admin_sees_all_media_with_moderation_fields(): void
    {
        $this->fakeStorage();
        $admin = User::factory()->admin()->create();
        $a = User::factory()->approved()->create();
        $b = User::factory()->approved()->create();
        Media::factory()->for($a)->unlisted()->create();
        Media::factory()->for($b)->create();

        $response = $this->actingAs($admin)->getJson('/api/admin/media')->assertOk();

        $response->assertJsonCount(2, 'data');
        // Admin view DOES include the internal review state.
        $response->assertJsonPath('data.0.moderation_status', 'pending');
        $this->assertArrayHasKey('user', $response->json('data.0'));
    }

    public function test_admin_review_signs_thumbnail_for_review(): void
    {
        $this->fakeStorage();
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        // A row carrying a client-uploaded thumbnail/poster.
        Media::factory()->for($owner)->create(['thumbnail_key' => 'uploads/thumbnails/0/thumb.jpg']);

        // The reviewer must receive the thumbnail URL so the client-supplied
        // image is reviewed, not just the source object.
        $this->actingAs($admin)->getJson('/api/admin/media')
            ->assertOk()
            ->assertJsonPath('data.0.thumbnail_url', 'https://r2.example/view');
    }

    public function test_admin_review_exposes_original_video_url_and_download_url(): void
    {
        $this->fakeStorage();
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        Media::factory()->for($owner)->video()->create();

        $this->actingAs($admin)->getJson('/api/admin/media')
            ->assertOk()
            ->assertJsonPath('data.0.url', 'https://r2.example/view')
            ->assertJsonPath('data.0.download_url', 'https://r2.example/download')
            ->assertJsonPath('data.0.video.status', 'processing');
    }

    public function test_non_admin_cannot_access_admin_media(): void
    {
        // User id 1 is always an admin, so occupy it with a filler first.
        User::factory()->admin()->create();
        $user = User::factory()->approved()->create();
        $this->actingAs($user)->getJson('/api/admin/media')->assertForbidden();
    }

    public function test_admin_can_approve_and_reject(): void
    {
        $this->fakeStorage();
        $admin = User::factory()->admin()->create();
        $media = Media::factory()->create();

        $this->actingAs($admin)->postJson("/api/admin/media/{$media->id}/moderate", [
            'action' => 'approve',
            'notes' => 'ok',
        ])->assertOk()->assertJsonPath('data.moderation_status', 'approved');

        $fresh = $media->fresh();
        $this->assertSame($admin->id, $fresh->moderated_by_user_id);
        $this->assertSame('ok', $fresh->moderation_notes);
        $this->assertSame('uploads/reviewed/'.$fresh->user_id.'/'.$fresh->ulid.'.jpg', $fresh->reviewed_object_key);

        $this->actingAs($admin)->postJson("/api/admin/media/{$media->id}/moderate", [
            'action' => 'reject',
        ])->assertOk()->assertJsonPath('data.moderation_status', 'rejected');
    }

    public function test_admin_approval_copies_source_and_thumbnail_to_reviewed_keys(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        $reviewedKey = "uploads/reviewed/{$owner->id}/01HZ0000000000000000000000.jpg";
        $reviewedThumbnailKey = "uploads/reviewed-thumbnails/{$owner->id}/01HZ0000000000000000000000.jpg";

        $this->mock(FileStorageService::class, function (MockInterface $mock) use ($reviewedKey, $reviewedThumbnailKey): void {
            $mock->shouldReceive('fileExists')->once()->with('photos', 'uploads/0/source.jpg')->andReturn(true);
            $mock->shouldReceive('copyFile')->once()
                ->with('photos', 'uploads/0/source.jpg', 'photos', $reviewedKey, 'image/jpeg')
                ->andReturn(true);
            $mock->shouldReceive('copyFile')->once()
                ->with('photos', 'uploads/thumbnails/0/source.jpg', 'photos', $reviewedThumbnailKey, 'image/jpeg')
                ->andReturn(true);
            $mock->shouldReceive('getSignedViewUrl')->andReturnUsing(fn (string $disk, string $key): string => 'signed:'.$disk.':'.$key);
            $mock->shouldReceive('get')->andReturn(null);
        });

        $media = Media::factory()->for($owner)->create([
            'ulid' => '01HZ0000000000000000000000',
            'disk' => 'photos',
            'object_key' => 'uploads/0/source.jpg',
            'thumbnail_key' => 'uploads/thumbnails/0/source.jpg',
        ]);

        $this->actingAs($admin)->postJson("/api/admin/media/{$media->id}/moderate", [
            'action' => 'approve',
        ])->assertOk()
            ->assertJsonPath('data.url', 'signed:photos:'.$reviewedKey)
            ->assertJsonPath('data.thumbnail_url', 'signed:photos:'.$reviewedThumbnailKey);

        $fresh = $media->fresh();
        $this->assertSame($reviewedKey, $fresh->reviewed_object_key);
        $this->assertSame($reviewedThumbnailKey, $fresh->reviewed_thumbnail_key);
    }

    public function test_pending_uploads_are_excluded_from_review_queue(): void
    {
        $this->fakeStorage();
        $admin = User::factory()->admin()->create();
        Media::factory()->create();                 // ready, reviewable
        Media::factory()->pendingUpload()->create(); // not yet uploaded

        $this->actingAs($admin)->getJson('/api/admin/media')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_cannot_moderate_a_pending_upload(): void
    {
        $this->fakeStorage();
        $admin = User::factory()->admin()->create();
        $media = Media::factory()->pendingUpload()->create();

        $this->actingAs($admin)->postJson("/api/admin/media/{$media->id}/moderate", [
            'action' => 'approve',
        ])->assertStatus(422);

        $this->assertTrue($media->fresh()->isPendingReview());
    }

    public function test_review_queue_is_paginated(): void
    {
        $this->fakeStorage();
        config(['media.page_size' => 2]);
        $admin = User::factory()->admin()->create();
        Media::factory()->count(3)->create(); // ready, pending

        $this->actingAs($admin)->getJson('/api/admin/media')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.has_more', true);

        $this->actingAs($admin)->getJson('/api/admin/media?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_status_filter(): void
    {
        $this->fakeStorage();
        $admin = User::factory()->admin()->create();
        Media::factory()->approved()->create();
        Media::factory()->create(); // pending

        $this->actingAs($admin)->getJson('/api/admin/media?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.moderation_status', 'pending');
    }
}
