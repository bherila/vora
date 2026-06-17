<?php

namespace Tests\Feature\Media;

use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class MediaHlsProxyTest extends TestCase
{
    use RefreshDatabase;

    private function seedHls(Media $media): void
    {
        Storage::fake('hls');
        Storage::disk('hls')->put('mappings/'.$media->object_key.'.json', json_encode(['contentId' => 'sha256:abc']));
        Storage::disk('hls')->put('by-id/sha256:abc/master.m3u8', "#EXTM3U\n720p/index.m3u8\n");
        Storage::disk('hls')->put('by-id/sha256:abc/720p/index.m3u8', "#EXTM3U\nseg_0.m4s\n");

        // get()/exists() run against the fake disk; only presigning is stubbed.
        $this->partialMock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/seg_0.m4s');
        });
    }

    public function test_manifest_is_served_inline_with_rewritten_child_uris(): void
    {
        $owner = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->video()->create(['disk' => 's3']);
        $this->seedHls($media);

        $response = $this->actingAs($owner)
            ->withHeader('User-Agent', 'Vora test player')
            ->get("/api/media/{$media->id}/hls/master.m3u8");

        $response->assertOk();
        $this->assertSame('application/vnd.apple.mpegurl', $response->headers->get('Content-Type'));
        $response->assertSee("/api/media/{$media->id}/hls/720p/index.m3u8", false);
        $this->assertDatabaseHas('media_playback_audit_logs', [
            'media_id' => $media->id,
            'user_id' => $owner->id,
            'action' => 'hls_manifest',
            'path' => 'master.m3u8',
            'user_agent' => 'Vora test player',
        ]);
    }

    public function test_segment_is_redirected_to_presigned_url(): void
    {
        $owner = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->video()->create(['disk' => 's3']);
        $this->seedHls($media);

        $this->actingAs($owner)
            ->withHeader('User-Agent', 'Vora test player')
            ->get("/api/media/{$media->id}/hls/720p/seg_0.m4s")
            ->assertRedirect('https://r2.example/seg_0.m4s');

        $this->assertDatabaseHas('media_playback_audit_logs', [
            'media_id' => $media->id,
            'user_id' => $owner->id,
            'action' => 'hls_segment_redirect',
            'path' => '720p/seg_0.m4s',
            'user_agent' => 'Vora test player',
        ]);
    }

    public function test_unsafe_path_is_rejected(): void
    {
        $owner = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->video()->create(['disk' => 's3']);
        $this->seedHls($media);

        $this->actingAs($owner)->get("/api/media/{$media->id}/hls/..%2Fsecret")->assertStatus(422);
    }

    public function test_other_user_cannot_stream_pending_video(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->video()->create(['disk' => 's3']); // pending review
        $this->seedHls($media);

        // 404 (not 403): a non-owner can't tell a private/pending video apart
        // from a video that doesn't exist.
        $this->actingAs($viewer)->get("/api/media/{$media->id}/hls/master.m3u8")->assertNotFound();
    }
}
