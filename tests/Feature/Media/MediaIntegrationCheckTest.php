<?php

namespace Tests\Feature\Media;

use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaIntegrationCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_opt_in_and_four_valid_identifiers_are_required_before_storage_reads(): void
    {
        $this->mock(FileStorageService::class)->shouldNotReceive('readStream');
        $this->artisan('ops:media-integration-check --json')
            ->expectsOutputToContain('explicit_storage_opt_in_required')->assertFailed();
        $this->artisan('ops:media-integration-check --read-storage --photo=1 --json')
            ->expectsOutputToContain('four_test_ulids_required')->assertFailed();
    }

    public function test_retained_objects_and_hls_can_be_checked_without_database_or_storage_writes(): void
    {
        [$options, $video] = $this->fixtures();
        $files = Storage::disk('hls')->allFiles();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->artisan('ops:media-integration-check', $options)
            ->expectsOutputToContain('"healthy":true')->assertSuccessful();

        foreach (DB::getQueryLog() as $query) {
            $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])));
        }
        DB::disableQueryLog();
        $this->assertNull($video->fresh()->hls_content_id);
        $this->assertSame($files, Storage::disk('hls')->allFiles());
    }

    public function test_missing_or_wrong_sized_reviewed_object_fails_even_when_the_original_exists(): void
    {
        [$options, $video] = $this->fixtures();
        Storage::disk('s3')->put($video->reviewed_object_key, 'bad');

        $this->artisan('ops:media-integration-check', $options)
            ->expectsOutputToContain('"video_reviewed":false')->assertFailed();
    }

    public function test_missing_mapping_and_unsafe_or_missing_hls_children_fail(): void
    {
        [$options, $video] = $this->fixtures();
        $mappingKey = 'mappings/'.$video->reviewed_object_key.'.json';
        $mapping = Storage::disk('hls')->get($mappingKey);
        Storage::disk('hls')->delete($mappingKey);
        $this->artisan('ops:media-integration-check', $options)
            ->expectsOutputToContain('"video_hls_artifacts":false')->assertFailed();

        Storage::disk('hls')->put($mappingKey, $mapping);
        Storage::disk('hls')->put('by-id/test-content/master.m3u8', "#EXTM3U\nhttps://untrusted.invalid/seg.m4s\n");
        $this->artisan('ops:media-integration-check', $options)
            ->expectsOutputToContain('"video_hls_artifacts":false')->assertFailed();

        Storage::disk('hls')->put('by-id/test-content/master.m3u8', "#EXTM3U\nmissing.m4s\n");
        $this->artisan('ops:media-integration-check', $options)
            ->expectsOutputToContain('"video_hls_artifacts":false')->assertFailed();
    }

    public function test_unrelated_reference_and_live_uploader_do_not_pass_retention_check(): void
    {
        [$options, $video] = $this->fixtures();
        $video->update(['user_id' => User::factory()->approved()->create()->id]);
        $options['--saved-video'] = $options['--saved-photo'];

        $this->artisan('ops:media-integration-check', $options)
            ->expectsOutputToContain('"video_saved_reference":false,"video_uploader_removed":false')->assertFailed();
    }

    public function test_large_manifests_and_excessive_hls_artifacts_fail_with_bounded_work(): void
    {
        [$options] = $this->fixtures();
        Storage::disk('hls')->put('by-id/test-content/master.m3u8', str_repeat('x', 65537));
        $this->artisan('ops:media-integration-check', $options)
            ->expectsOutputToContain('"video_hls_artifacts":false')->assertFailed();

        $children = array_map(fn (int $number): string => 'seg'.$number.'.m4s', range(1, 129));
        Storage::disk('hls')->put('by-id/test-content/master.m3u8', "#EXTM3U\n".implode("\n", $children));
        $this->artisan('ops:media-integration-check', $options)
            ->expectsOutputToContain('"video_hls_artifacts":false')->assertFailed();
    }

    public function test_storage_exception_details_are_never_printed(): void
    {
        [$options] = $this->fixtures();
        $this->mock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getFileSize')->andThrow(new \RuntimeException('secret-signed-storage-url'));
            $mock->shouldReceive('readStream')->andReturn(null);
        });
        $this->artisan('ops:media-integration-check', $options)
            ->doesntExpectOutputToContain('secret-signed-storage-url')
            ->expectsOutputToContain('"healthy":false')->assertFailed();
    }

    public function test_a_mapping_that_disagrees_with_cached_playback_state_fails(): void
    {
        [$options, $video] = $this->fixtures();
        $video->hls_content_id = 'stale-content';
        $video->saveQuietly();

        $this->artisan('ops:media-integration-check', $options)
            ->expectsOutputToContain('"video_hls_artifacts":false')->assertFailed();
        $this->assertSame('stale-content', $video->fresh()->hls_content_id);
    }

    /** @return array{array<string, string|bool>, Media} */
    private function fixtures(): array
    {
        foreach (['photos', 's3', 'hls'] as $disk) {
            Storage::fake($disk);
        }
        $this->partialMock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://storage.invalid/signed-segment');
        });
        $saver = User::factory()->approved()->create();
        $photo = Media::factory()->approved()->create([
            'user_id' => null, 'deleted_at' => now(), 'size_bytes' => 8, 'disk' => 'photos',
            'reviewed_object_key' => 'reviewed/photo.jpg',
        ]);
        $video = Media::factory()->video()->approved()->create([
            'user_id' => null, 'deleted_at' => now(), 'size_bytes' => 8, 'disk' => 's3',
            'reviewed_object_key' => 'reviewed/video.mp4',
        ]);
        $options = ['--read-storage' => true, '--expect-uploader-removed' => true, '--json' => true];
        foreach (['photo' => $photo, 'video' => $video] as $name => $media) {
            Storage::disk($media->disk)->put($media->object_key, 'original');
            Storage::disk($media->disk)->put($media->reviewed_object_key, 'reviewed');
            $item = $saver->libraryItems()->create(['media_id' => $media->id, 'source' => 'saved']);
            $options['--'.$name] = $media->ulid;
            $options['--saved-'.$name] = $item->ulid;
        }
        Storage::disk('hls')->put('mappings/'.$video->reviewed_object_key.'.json', '{"contentId":"test-content"}');
        Storage::disk('hls')->put('by-id/test-content/master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1000\n360p/index.m3u8\n");
        Storage::disk('hls')->put('by-id/test-content/360p/index.m3u8', "#EXTM3U\n#EXT-X-MAP:URI=\"init.mp4\"\n#EXTINF:1,\nseg.m4s\n#EXT-X-ENDLIST\n");
        Storage::disk('hls')->put('by-id/test-content/360p/init.mp4', 'init');
        Storage::disk('hls')->put('by-id/test-content/360p/seg.m4s', 'segment');

        return [$options, $video];
    }
}
