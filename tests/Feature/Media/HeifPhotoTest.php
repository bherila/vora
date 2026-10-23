<?php

namespace Tests\Feature\Media;

use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\Media\AccountStorageUsage;
use App\Services\Media\MediaModerationService;
use App\Services\Media\MediaResponseService;
use App\Services\Media\MediaUploadService;
use App\Services\UserAccountService;
use App\Support\HeifPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HeifPhotoTest extends TestCase
{
    use RefreshDatabase;

    private function original(string $brand = 'heic'): string
    {
        return pack('N', 24).'ftyp'.$brand.pack('N', 0).'mif1'.$brand.'original-photo-bytes';
    }

    private function preview(int $width = 16, int $height = 8): string
    {
        return UploadedFile::fake()->image('preview.jpg', $width, $height)->getContent();
    }

    private function pending(string $mime = 'image/heic'): Media
    {
        Storage::fake('photos');
        $media = Media::factory()->pendingUpload()->create([
            'disk' => 'photos', 'mime_type' => $mime, 'original_filename' => 'IMG_1234.HEIC',
            'thumbnail_key' => 'thumbnails/preview.jpg',
        ]);
        Storage::disk('photos')->put($media->object_key, $this->original());

        return $media;
    }

    public function test_heic_and_heif_uploads_reserve_originals_and_require_a_jpeg_preview(): void
    {
        $owner = User::factory()->approved()->create();
        $this->mock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getSignedUploadUrl')->times(4)->andReturn(['url' => 'https://storage.test/upload', 'headers' => []]);
        });
        foreach (['image/heic', 'image/heif'] as $mime) {
            $payload = ['type' => 'photo', 'filename' => 'camera-photo', 'content_type' => $mime, 'size' => 30, 'audience' => 'everyone'];
            $this->actingAs($owner)->postJson('/api/media', $payload)->assertUnprocessable()->assertJsonValidationErrors('has_thumbnail');
            $created = $this->postJson('/api/media', $payload + ['has_thumbnail' => true, 'thumbnail_size' => 50])->assertCreated();
            $media = Media::findOrFail($created->json('data.id'));
            $this->assertStringEndsWith('.'.substr($mime, 6), $media->object_key);
            $this->assertSame($mime, $media->mime_type);
            $this->assertSame(30, $media->multipart_expected_size_bytes);
        }
        $this->postJson('/api/media', ['type' => 'photo', 'filename' => 'sequence.heics', 'content_type' => 'image/heic-sequence', 'size' => 30, 'audience' => 'everyone'])
            ->assertUnprocessable()->assertJsonValidationErrors('content_type');
    }

    public function test_completion_requires_a_landed_bounded_jpeg_and_supported_still_container(): void
    {
        $media = $this->pending();
        $uploads = app(MediaUploadService::class);
        $this->assertFalse($uploads->completeUpload($media));
        Storage::disk('photos')->put($media->thumbnail_key, 'not-a-jpeg');
        $this->assertFalse($uploads->completeUpload($media));
        Storage::disk('photos')->put($media->thumbnail_key, $this->preview(2049, 2));
        $this->assertFalse($uploads->completeUpload($media));
        Storage::disk('photos')->put($media->thumbnail_key, $this->preview());
        Storage::disk('photos')->put($media->object_key, $this->original('hevc'));
        $this->assertFalse($uploads->completeUpload($media));
        Storage::disk('photos')->put($media->object_key, '<svg>not-a-heic-photo</svg>');
        $this->assertFalse($uploads->completeUpload($media));
        $this->assertSame('pending', $media->fresh()->upload_status);
        Storage::disk('photos')->put($media->object_key, $this->original());
        $this->assertTrue($uploads->completeUpload($media));
        $this->assertSame(strlen($this->original()), $media->fresh()->size_bytes);
        $this->assertSame($this->original(), Storage::disk('photos')->get($media->object_key));
        $this->assertSame(strlen($this->original()), app(AccountStorageUsage::class)->forUser($media->user)['used_bytes']);
    }

    public function test_oversized_preview_does_not_publish_or_discard_the_retryable_original(): void
    {
        $media = $this->pending();
        config(['media.thumbnail.max_bytes' => 10]);
        Storage::disk('photos')->put($media->thumbnail_key, $this->preview());
        $this->assertFalse(app(MediaUploadService::class)->completeUpload($media));
        $this->assertSame('pending', $media->fresh()->upload_status);
        Storage::disk('photos')->assertExists($media->object_key);
    }

    public function test_reviewed_preview_and_original_survive_uploader_purge_for_a_private_saver(): void
    {
        $media = $this->pending('image/heif');
        $owner = $media->user;
        $viewer = User::factory()->approved()->create(['library_enabled' => true]);
        $outsider = User::factory()->approved()->create(['library_enabled' => true]);
        $admin = User::factory()->approved()->admin()->create();
        $preview = $this->preview();
        Storage::disk('photos')->put($media->thumbnail_key, $preview);
        $this->assertTrue(app(MediaUploadService::class)->completeUpload($media));
        $this->assertTrue(app(MediaModerationService::class)->approve($media->fresh(), $admin));
        $media->refresh();
        $this->assertStringEndsWith('/thumbnail', app(MediaResponseService::class)->visitorItem($media)['url']);
        $url = app(MediaResponseService::class)->visitorItem($media)['url'];
        $this->actingAs($viewer)->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertStreamedContent($preview);
        $saved = $this->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertCreated()->json('data.ulid');
        $this->getJson("/api/library/items/{$saved}")->assertOk()->assertJsonPath('data.media.url', "/api/library/items/{$saved}/assets/thumbnail");
        Storage::disk('photos')->put($media->object_key, 'changed-unreviewed-original');
        Storage::disk('photos')->put($media->thumbnail_key, 'changed-unreviewed-preview');
        $this->get($url)->assertOk()->assertStreamedContent($preview);
        app(UserAccountService::class)->purge($owner);
        $this->get("/api/library/items/{$saved}/assets/thumbnail")->assertOk()->assertStreamedContent($preview);
        $this->get("/api/library/items/{$saved}/assets/original")->assertOk()->assertHeader('Content-Type', 'image/heif')->assertStreamedContent($this->original());
        $this->actingAs($outsider)->get("/api/library/items/{$saved}/assets/thumbnail")->assertNotFound();
        $this->actingAs($viewer)->deleteJson("/api/library/items/{$saved}")->assertOk();
        $this->assertCount(0, Storage::disk('photos')->allFiles());
    }

    public function test_container_check_rejects_truncation_and_sequences_without_decoding_images(): void
    {
        $this->assertTrue(HeifPhoto::hasStillImageBrand($this->original()));
        $this->assertFalse(HeifPhoto::hasStillImageBrand(substr($this->original(), 0, 20)));
        $this->assertFalse(HeifPhoto::hasStillImageBrand($this->original('hevc')));
        $this->assertFalse(HeifPhoto::hasStillImageBrand($this->original('msf1')));
        $this->assertFalse(HeifPhoto::hasStillImageBrand(pack('N', 4097).substr($this->original(), 4)));
        $this->assertSame('heic', (new Media(['mime_type' => 'image/heic']))->downloadExtension());
        $this->assertSame('heif', (new Media(['mime_type' => 'image/heif']))->downloadExtension());
    }
}
