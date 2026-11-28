<?php

namespace Tests\Feature\Media;

use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class SceneSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // User 1 is the primary admin; keep it out of these ownership tests.
        User::factory()->approved()->create();
        Storage::fake('hls');
        $this->partialMock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturnUsing(fn (string $disk, string $key): string => "https://r2.example/{$key}");
        });
    }

    private function analysedVideo(User $owner, bool $withAnalysis = true): Media
    {
        $media = Media::factory()->for($owner)->video()->approved()->create(['disk' => 's3']);
        Storage::disk('hls')->put('mappings/'.$media->playbackObjectKey().'.json', json_encode(['contentId' => 'sha256:abc']));
        Storage::disk('hls')->put('by-id/sha256:abc/master.m3u8', "#EXTM3U\n");
        $metadata = ['contentId' => 'sha256:abc', 'source' => ['durationSeconds' => 120]];
        if ($withAnalysis) {
            $metadata['analysis'] = ['scenes' => ['key' => 'scenes.json', 'detectorVersion' => 1]];
            Storage::disk('hls')->put('by-id/sha256:abc/scenes.json', json_encode([
                'schema' => 1,
                'durationMs' => 120_000,
                'cuts' => [
                    ['ms' => 5_000, 'score' => 40, 'thumbnail' => 'scenes/5000.jpg'],
                    ['ms' => 12_000, 'score' => 9],
                    ['ms' => 30_000, 'score' => 30, 'thumbnail' => 'scenes/30000.jpg'],
                    ['ms' => 31_000, 'score' => 50, 'thumbnail' => 'scenes/31000.jpg'],
                    ['ms' => 'soon', 'score' => 90],
                    ['ms' => 200_000, 'score' => 99],
                ],
                'blackSegments' => [['startMs' => 60_000, 'endMs' => 62_000, 'thumbnail' => 'scenes/62000.jpg']],
                'truncated' => false,
            ]));
        }
        Storage::disk('hls')->put('by-id/sha256:abc/metadata.json', json_encode($metadata));

        return $media;
    }

    public function test_uploader_gets_the_strongest_starts_spaced_by_the_minimum_gap(): void
    {
        $owner = User::factory()->approved()->create();
        $media = $this->analysedVideo($owner);

        $this->actingAs($owner)->getJson("/api/media/by-ulid/{$media->ulid}/scene-suggestions")
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['analysed' => true, 'suggestions' => [
                ['ms' => 31_000, 'kind' => 'cut', 'score' => 50, 'thumbnail_url' => "/api/media/by-ulid/{$media->ulid}/scene-suggestions/31000/thumbnail"],
                ['ms' => 62_000, 'kind' => 'black', 'score' => 100, 'thumbnail_url' => "/api/media/by-ulid/{$media->ulid}/scene-suggestions/62000/thumbnail"],
            ]]]);

        $close = $this->actingAs($owner)->getJson("/api/media/by-ulid/{$media->ulid}/scene-suggestions?min_gap_seconds=1")->assertOk();
        $this->assertSame([5_000, 12_000, 30_000, 31_000, 62_000], array_column($close->json('data.suggestions'), 'ms'));
        $this->assertNull($close->json('data.suggestions.1.thumbnail_url'));
    }

    public function test_videos_without_an_analysis_report_none(): void
    {
        $owner = User::factory()->approved()->create();
        $media = $this->analysedVideo($owner, withAnalysis: false);

        $this->actingAs($owner)->getJson("/api/media/by-ulid/{$media->ulid}/scene-suggestions")
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['analysed' => false, 'suggestions' => []]]);
    }

    public function test_only_the_uploader_sees_suggestions_and_thumbnails(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $media = $this->analysedVideo($owner);

        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$media->ulid}/scene-suggestions")->assertForbidden();
        $this->actingAs($viewer)->get("/api/media/by-ulid/{$media->ulid}/scene-suggestions/31000/thumbnail")->assertNotFound();
        $this->actingAs($owner)->get("/api/media/by-ulid/{$media->ulid}/scene-suggestions/31000/thumbnail")
            ->assertRedirect('https://r2.example/by-id/sha256:abc/scenes/31000.jpg');
    }

    public function test_thumbnails_must_be_named_by_the_analysis(): void
    {
        $owner = User::factory()->approved()->create();
        $media = $this->analysedVideo($owner);

        $this->actingAs($owner)->get("/api/media/by-ulid/{$media->ulid}/scene-suggestions/12000/thumbnail")->assertNotFound();
        $this->actingAs($owner)->get("/api/media/by-ulid/{$media->ulid}/scene-suggestions/99999/thumbnail")->assertNotFound();
    }

    public function test_the_gap_is_bounded(): void
    {
        $owner = User::factory()->approved()->create();
        $media = $this->analysedVideo($owner);

        foreach (['0', '601', 'soon'] as $gap) {
            $this->actingAs($owner)->getJson("/api/media/by-ulid/{$media->ulid}/scene-suggestions?min_gap_seconds={$gap}")
                ->assertUnprocessable()->assertJsonValidationErrors('min_gap_seconds');
        }
    }

    public function test_the_playback_proxy_serves_only_player_files(): void
    {
        $owner = User::factory()->approved()->create();
        $media = $this->analysedVideo($owner);
        Storage::disk('hls')->put('by-id/sha256:abc/frames/1000.jpg', 'jpeg');

        foreach (['metadata.json', 'scenes.json', 'scenes/31000.jpg', 'frames/1000.jpg'] as $path) {
            $this->actingAs($owner)->get("/api/media/{$media->id}/hls/{$path}")->assertNotFound();
        }
        $this->actingAs($owner)->get("/api/media/{$media->id}/hls/master.m3u8")->assertOk();
        $this->actingAs($owner)->get("/api/media/{$media->id}/hls/720p/init_t.mp4")->assertRedirect();
    }
}
