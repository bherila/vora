<?php

namespace Tests\Feature\Media;

use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class MediaMultipartTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_initiates_multipart_for_large_video(): void
    {
        config(['media.multipart_threshold' => 1000]);
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createMultipartUpload')->once()->andReturn('upl-1');
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
        });

        $user = User::factory()->approved()->create();

        $this->actingAs($user)->postJson('/api/media', [
            'type' => 'video',
            'filename' => 'big.mp4',
            'content_type' => 'video/mp4',
            'size' => 5000,
            'visibility' => 'users',
        ])->assertCreated()
            ->assertJsonPath('multipart.upload_id', 'upl-1')
            ->assertJsonMissingPath('upload_url');

        $this->assertDatabaseHas('media', ['user_id' => $user->id, 'upload_status' => 'pending', 'type' => 'video']);
    }

    public function test_store_uses_single_put_for_small_file(): void
    {
        config(['media.multipart_threshold' => 1_000_000_000]);
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedUploadUrl')->once()->andReturn([
                'url' => 'https://r2.example/put',
                'headers' => [],
            ]);
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
        });

        $user = User::factory()->approved()->create();

        $this->actingAs($user)->postJson('/api/media', [
            'type' => 'video',
            'filename' => 'small.mp4',
            'content_type' => 'video/mp4',
            'size' => 2048,
            'visibility' => 'users',
        ])->assertCreated()
            ->assertJsonPath('upload_url', 'https://r2.example/put')
            ->assertJsonMissingPath('multipart');
    }

    public function test_presign_part_returns_url(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('presignUploadPart')->once()->andReturn('https://r2.example/part?n=2');
        });

        $user = User::factory()->approved()->create();
        $media = Media::factory()->for($user)->video()->pendingUpload()->create();

        $this->actingAs($user)->postJson("/api/media/{$media->id}/multipart/part", [
            'upload_id' => 'upl-1',
            'part_number' => 2,
        ])->assertOk()->assertJsonPath('url', 'https://r2.example/part?n=2');
    }

    public function test_complete_multipart_marks_ready(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('completeMultipartUpload')->once()->andReturn(true);
            $mock->shouldReceive('fileExists')->andReturn(true);
            $mock->shouldReceive('getFileSize')->andReturn(2048);
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
            $mock->shouldReceive('get')->andReturn(null);
        });

        $user = User::factory()->approved()->create();
        $media = Media::factory()->for($user)->video()->pendingUpload()->create();

        $this->actingAs($user)->postJson("/api/media/{$media->id}/multipart/complete", [
            'upload_id' => 'upl-1',
            'parts' => [
                ['part_number' => 1, 'etag' => '"abc"'],
                ['part_number' => 2, 'etag' => '"def"'],
            ],
        ])->assertOk()->assertJsonPath('data.upload_status', 'ready');

        $this->assertSame('ready', $media->fresh()->upload_status);
    }

    public function test_abort_multipart_deletes_pending_row(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('abortMultipartUpload')->once()->andReturn(true);
        });

        $user = User::factory()->approved()->create();
        $media = Media::factory()->for($user)->video()->pendingUpload()->create();

        $this->actingAs($user)->postJson("/api/media/{$media->id}/multipart/abort", [
            'upload_id' => 'upl-1',
        ])->assertOk();

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_multipart_part_forbidden_for_non_owner(): void
    {
        $owner = User::factory()->approved()->create();
        $intruder = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->video()->pendingUpload()->create();

        $this->actingAs($intruder)->postJson("/api/media/{$media->id}/multipart/part", [
            'upload_id' => 'upl-1',
            'part_number' => 1,
        ])->assertForbidden();
    }
}
