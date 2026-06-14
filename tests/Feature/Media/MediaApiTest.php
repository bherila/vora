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
            'visibility' => 'users',
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
            'visibility' => 'users',
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

    public function test_user_cannot_complete_or_delete_another_users_media(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $intruder = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->pendingUpload()->create();

        $this->actingAs($intruder)->postJson("/api/media/{$media->id}/complete")->assertForbidden();
        $this->actingAs($intruder)->deleteJson("/api/media/{$media->id}")->assertForbidden();
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

        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$pending->ulid}")->assertForbidden();
    }
}
