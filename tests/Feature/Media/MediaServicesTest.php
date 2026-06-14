<?php

namespace Tests\Feature\Media;

use App\Enums\MediaType;
use App\Enums\Visibility;
use App\Models\Interest;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\Media\HlsMappingService;
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
            $mock->shouldReceive('getSignedUploadUrl')->once()->andReturn('https://r2.example/signed-put');
        });

        $user = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Cycling']);

        $result = app(MediaUploadService::class)->createPendingUpload(
            $user,
            MediaType::Photo,
            'My Vacation.JPG',
            'image/jpeg',
            'Vacation',
            Visibility::Users,
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

    public function test_hls_resolve_processing_then_ready(): void
    {
        Storage::fake('hls');
        config(['media.hls_base_url' => 'https://cdn.example/hls']);
        $media = Media::factory()->video()->create(['disk' => 's3']);
        $service = app(HlsMappingService::class);

        $this->assertSame('processing', $service->resolve($media)['status']);

        Storage::disk('hls')->put(
            'mappings/'.$media->object_key.'.json',
            json_encode(['hlsRoot' => 'by-id/sha256:abc/master.m3u8']),
        );

        $resolved = $service->resolve($media);
        $this->assertSame('ready', $resolved['status']);
        $this->assertSame('https://cdn.example/hls/by-id/sha256:abc/master.m3u8', $resolved['playback_url']);
    }

    public function test_delete_removes_source_object_and_row(): void
    {
        Storage::fake('photos');
        $media = Media::factory()->create(['disk' => 'photos']);
        Storage::disk('photos')->put($media->object_key, 'data');

        app(MediaService::class)->delete($media);

        Storage::disk('photos')->assertMissing($media->object_key);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }
}
