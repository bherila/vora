<?php

namespace Tests\Feature\Media;

use App\Enums\Audience;
use App\Enums\MediaType;
use App\Models\Interest;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\Media\HlsService;
use App\Services\Media\MediaModerationService;
use App\Services\Media\MediaService;
use App\Services\Media\MediaUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_pending_upload_persists_record_and_interests(): void
    {
        $this->mock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getSignedUploadUrl')->once()->andReturn([
                'url' => 'https://r2.example/signed-put',
                'headers' => ['Content-Type' => 'image/jpeg'],
            ]);
        });

        $user = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Cycling']);

        $result = app(MediaUploadService::class)->createPendingUpload(
            $user,
            MediaType::Photo,
            'My Vacation.JPG',
            'image/jpeg',
            'Vacation',
            Audience::Everyone,
            [$interest->id],
        );

        $media = $result['media'];
        $this->assertSame('https://r2.example/signed-put', $result['upload_url']);
        $this->assertSame('pending', $media->upload_status);
        $this->assertSame('photos', $media->disk);
        $this->assertStringStartsWith('uploads/'.$user->id.'/', $media->object_key);
        $this->assertStringEndsWith('.jpg', $media->object_key);
        $this->assertTrue($media->interests->contains($interest));
        $this->assertSame([$interest->id], $user->fresh()->last_media_interest_ids);
    }

    public function test_complete_upload_marks_ready_with_real_size(): void
    {
        Storage::fake('photos');
        $media = Media::factory()->pendingUpload()->create(['disk' => 'photos']);
        Storage::disk('photos')->put($media->object_key, str_repeat('x', 1234));

        $ok = app(MediaUploadService::class)->completeUpload($media);

        $this->assertTrue($ok);
        $this->assertSame('ready', $media->fresh()->upload_status);
        $this->assertSame(1234, $media->fresh()->size_bytes);
    }

    public function test_complete_upload_returns_false_when_object_missing(): void
    {
        Storage::fake('photos');
        $media = Media::factory()->pendingUpload()->create(['disk' => 'photos']);

        $this->assertFalse(app(MediaUploadService::class)->completeUpload($media));
        $this->assertSame('pending', $media->fresh()->upload_status);
    }

    public function test_complete_upload_rejects_oversized_object(): void
    {
        Storage::fake('photos');
        config(['media.photo.max_bytes' => 10]);
        $media = Media::factory()->pendingUpload()->create(['disk' => 'photos']);
        Storage::disk('photos')->put($media->object_key, str_repeat('x', 100));

        $this->assertFalse(app(MediaUploadService::class)->completeUpload($media));
        Storage::disk('photos')->assertMissing($media->object_key);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_complete_upload_keeps_thumbnail_within_size_limit(): void
    {
        Storage::fake('photos');
        config(['media.thumbnail.max_bytes' => 1000]);
        $media = Media::factory()->pendingUpload()->create([
            'disk' => 'photos',
            'thumbnail_key' => 'uploads/thumbnails/0/thumb.jpg',
        ]);
        Storage::disk('photos')->put($media->object_key, str_repeat('x', 100));
        Storage::disk('photos')->put($media->thumbnail_key, str_repeat('t', 500));

        $this->assertTrue(app(MediaUploadService::class)->completeUpload($media));

        $fresh = $media->fresh();
        $this->assertSame('uploads/thumbnails/0/thumb.jpg', $fresh->thumbnail_key);
        Storage::disk('photos')->assertExists('uploads/thumbnails/0/thumb.jpg');
    }

    public function test_complete_upload_drops_oversized_thumbnail_but_keeps_source(): void
    {
        Storage::fake('photos');
        config(['media.thumbnail.max_bytes' => 100]);
        $media = Media::factory()->pendingUpload()->create([
            'disk' => 'photos',
            'thumbnail_key' => 'uploads/thumbnails/0/thumb.jpg',
        ]);
        Storage::disk('photos')->put($media->object_key, str_repeat('x', 50));
        // The client PUT a multi-megabyte image to the thumbnail URL.
        Storage::disk('photos')->put($media->thumbnail_key, str_repeat('t', 5000));

        $this->assertTrue(app(MediaUploadService::class)->completeUpload($media));

        $fresh = $media->fresh();
        // Source upload still succeeds and is reviewable...
        $this->assertSame('ready', $fresh->upload_status);
        // ...but the oversized thumbnail is discarded, not served.
        $this->assertNull($fresh->thumbnail_key);
        Storage::disk('photos')->assertMissing('uploads/thumbnails/0/thumb.jpg');
    }

    public function test_complete_upload_drops_thumbnail_key_when_object_missing(): void
    {
        Storage::fake('photos');
        $media = Media::factory()->pendingUpload()->create([
            'disk' => 'photos',
            'thumbnail_key' => 'uploads/thumbnails/0/thumb.jpg',
        ]);
        Storage::disk('photos')->put($media->object_key, 'data');
        // Thumbnail was never PUT.

        $this->assertTrue(app(MediaUploadService::class)->completeUpload($media));
        $this->assertNull($media->fresh()->thumbnail_key);
    }

    public function test_complete_upload_resets_premature_approval_to_pending(): void
    {
        Storage::fake('photos');
        $admin = User::factory()->admin()->create();
        $media = Media::factory()->pendingUpload()->create(['disk' => 'photos']);
        // Simulate the bypass: approved before the object was uploaded.
        $media->approve($admin, 'too soon');
        Storage::disk('photos')->put($media->object_key, 'data');

        $this->assertTrue(app(MediaUploadService::class)->completeUpload($media));

        $fresh = $media->fresh();
        $this->assertTrue($fresh->isPendingReview());
        $this->assertNull($fresh->moderated_by_user_id);
    }

    public function test_complete_upload_is_idempotent_and_keeps_review_when_already_ready(): void
    {
        Storage::fake('photos');
        $media = Media::factory()->approved()->create(['disk' => 'photos']); // ready + approved

        $this->assertTrue(app(MediaUploadService::class)->completeUpload($media));
        $this->assertTrue($media->fresh()->isApprovedContent());
    }

    public function test_approval_copies_reviewed_source_and_thumbnail_before_serving(): void
    {
        Storage::fake('photos');
        $admin = User::factory()->admin()->create();
        $media = Media::factory()->create([
            'disk' => 'photos',
            'object_key' => 'uploads/0/source.jpg',
            'thumbnail_key' => 'uploads/thumbnails/0/source.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        Storage::disk('photos')->put($media->object_key, 'benign source');
        Storage::disk('photos')->put($media->thumbnail_key, 'benign thumbnail');

        $this->assertTrue(app(MediaModerationService::class)->approve($media, $admin));

        $fresh = $media->fresh();
        $this->assertTrue($fresh->isApprovedContent());
        $this->assertSame($fresh->reviewed_object_key, $fresh->playbackObjectKey());
        $this->assertSame($fresh->reviewed_thumbnail_key, $fresh->playbackThumbnailKey());
        $this->assertSame('benign source', Storage::disk('photos')->get($fresh->reviewed_object_key));
        $this->assertSame('benign thumbnail', Storage::disk('photos')->get($fresh->reviewed_thumbnail_key));

        Storage::disk('photos')->put($media->object_key, 'swapped source');
        Storage::disk('photos')->put($media->thumbnail_key, 'swapped thumbnail');

        $this->assertSame('benign source', Storage::disk('photos')->get($fresh->reviewed_object_key));
        $this->assertSame('benign thumbnail', Storage::disk('photos')->get($fresh->reviewed_thumbnail_key));
    }

    public function test_completion_clears_previous_reviewed_copies_when_reentering_review(): void
    {
        Storage::fake('photos');
        $media = Media::factory()->pendingUpload()->create([
            'disk' => 'photos',
            'reviewed_object_key' => 'uploads/reviewed/0/old.jpg',
            'reviewed_thumbnail_key' => 'uploads/reviewed-thumbnails/0/old.jpg',
        ]);
        Storage::disk('photos')->put($media->object_key, 'new source');

        $this->assertTrue(app(MediaUploadService::class)->completeUpload($media));

        $fresh = $media->fresh();
        $this->assertNull($fresh->reviewed_object_key);
        $this->assertNull($fresh->reviewed_thumbnail_key);
        $this->assertTrue($fresh->isPendingReview());
    }

    public function test_hls_status_processing_then_ready_with_proxy_url(): void
    {
        Storage::fake('hls');
        $media = Media::factory()->video()->create(['disk' => 's3']);
        $service = app(HlsService::class);

        $this->assertSame('processing', $service->status($media)['status']);

        Storage::disk('hls')->put(
            'mappings/'.$media->object_key.'.json',
            json_encode(['contentId' => 'sha256:abc']),
        );

        // The recheck guard caches the last lookup, so move past its window.
        $this->travel(3)->minutes();
        $resolved = $service->status($media->fresh());
        $this->assertSame('ready', $resolved['status']);
        $this->assertStringContainsString("/api/media/{$media->id}/hls/master.m3u8", $resolved['master_url']);
        $this->assertSame('sha256:abc', $media->fresh()->hls_content_id);
    }

    public function test_hls_resolves_approved_video_from_reviewed_copy_key(): void
    {
        Storage::fake('hls');
        $media = Media::factory()->video()->approved()->create([
            'disk' => 's3',
            'object_key' => 'uploads/0/original.mp4',
            'reviewed_object_key' => 'uploads/reviewed/0/original.mp4',
        ]);
        Storage::disk('hls')->put('mappings/uploads/0/original.mp4.json', json_encode(['contentId' => 'sha256:source']));
        Storage::disk('hls')->put('mappings/uploads/reviewed/0/original.mp4.json', json_encode(['contentId' => 'sha256:reviewed']));

        $resolved = app(HlsService::class)->status($media);

        $this->assertSame('ready', $resolved['status']);
        $this->assertSame('sha256:reviewed', $media->fresh()->hls_content_id);
    }

    public function test_hls_manifest_rewrites_child_uris_and_segments_presign(): void
    {
        Storage::fake('hls');
        $media = Media::factory()->video()->create(['disk' => 's3']);
        Storage::disk('hls')->put('mappings/'.$media->object_key.'.json', json_encode(['contentId' => 'sha256:abc']));
        Storage::disk('hls')->put('by-id/sha256:abc/master.m3u8', "#EXTM3U\n720p/index.m3u8\n");

        $service = app(HlsService::class);
        $this->assertTrue($service->ensureResolved($media->fresh()));

        $manifest = $service->manifest($media->fresh(), 'master.m3u8', fn (string $p): string => 'https://app.test/proxy/'.$p);
        $this->assertNotNull($manifest);
        $this->assertStringContainsString('https://app.test/proxy/720p/index.m3u8', $manifest['body']);

        $this->assertFalse($service->isSafeRelativePath('../secret'));
        $this->assertTrue($service->isManifestPath('720p/index.m3u8'));
    }

    public function test_delete_removes_source_object_and_row(): void
    {
        Storage::fake('photos');
        Storage::fake('hls');
        config(['media.pdq_disk' => 'hls']);
        $media = Media::factory()->create(['disk' => 'photos']);
        Storage::disk('photos')->put($media->object_key, 'data');
        Storage::disk('hls')->put('image-mappings/'.$media->object_key.'.json', json_encode(['pdqHash' => str_repeat('0', 64)]));

        app(MediaService::class)->delete($media);

        Storage::disk('photos')->assertMissing($media->object_key);
        Storage::disk('hls')->assertMissing('image-mappings/'.$media->object_key.'.json');
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_delete_removes_unshared_hls_output(): void
    {
        Storage::fake('videos');
        Storage::fake('hls');
        config(['media.hls_disk' => 'hls']);
        $media = Media::factory()->video()->create([
            'disk' => 'videos',
            'object_key' => 'uploads/0/video.mp4',
            'hls_content_id' => 'sha256:abc',
        ]);
        Storage::disk('videos')->put($media->object_key, 'video');
        Storage::disk('hls')->put('mappings/uploads/0/video.mp4.json', json_encode(['contentId' => 'sha256:abc']));
        Storage::disk('hls')->put('by-id/sha256:abc/master.m3u8', '#EXTM3U');

        app(MediaService::class)->delete($media);

        Storage::disk('videos')->assertMissing('uploads/0/video.mp4');
        Storage::disk('hls')->assertMissing('mappings/uploads/0/video.mp4.json');
        Storage::disk('hls')->assertMissing('by-id/sha256:abc/master.m3u8');
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }
}
