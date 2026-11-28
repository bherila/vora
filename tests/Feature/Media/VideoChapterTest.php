<?php

namespace Tests\Feature\Media;

use App\Models\Media;
use App\Models\User;
use App\Models\VideoChapter;
use App\Services\FileStorageService;
use App\Services\Media\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

class VideoChapterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // User 1 is the primary admin; keep it out of these ownership tests.
        User::factory()->approved()->create();
        Storage::fake('hls');
        // Reads go to the fake disk; only URL signing and deletes are stubbed.
        $this->partialMock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
            $mock->shouldReceive('getSignedDownloadUrl')->andReturn('https://r2.example/download');
            $mock->shouldReceive('deleteFile')->andReturn(true);
        });
    }

    private function video(User $owner, array $metadata = ['durationSeconds' => 3600.5, 'frameRate' => '30000/1001']): Media
    {
        $media = Media::factory()->for($owner)->video()->approved()->create(['disk' => 's3']);
        Storage::disk('hls')->put('mappings/'.$media->playbackObjectKey().'.json', json_encode(['contentId' => 'sha256:abc']));
        Storage::disk('hls')->put('by-id/sha256:abc/master.m3u8', "#EXTM3U\n");
        Storage::disk('hls')->put('by-id/sha256:abc/metadata.json', json_encode(['contentId' => 'sha256:abc', 'source' => $metadata]));

        return $media;
    }

    /** @param list<array{start_ms: int, title: string}> $chapters */
    private function putChapters(User $user, Media $media, array $chapters): TestResponse
    {
        return $this->actingAs($user)->putJson("/api/media/by-ulid/{$media->ulid}/chapters", ['chapters' => $chapters]);
    }

    public function test_uploader_replaces_chapters_and_the_view_payload_lists_them(): void
    {
        $owner = User::factory()->approved()->create();
        $media = $this->video($owner);

        $this->putChapters($owner, $media, [
            ['start_ms' => 751_000, 'title' => "Grandma's birthday"],
            ['start_ms' => 0, 'title' => "Christmas '89"],
        ])->assertOk()->assertExactJson(['success' => true, 'data' => [
            ['start_ms' => 0, 'title' => "Christmas '89"],
            ['start_ms' => 751_000, 'title' => "Grandma's birthday"],
        ]]);

        $this->putChapters($owner, $media, [['start_ms' => 0, 'title' => 'Only one']])->assertOk();
        $this->assertSame(['Only one'], $media->chapters()->pluck('title')->all());

        $page = $this->actingAs($owner)->get("/m/{$media->ulid}")->assertOk();
        $payload = $page->viewData('initialData')['mediaView'];
        $this->assertSame([['start_ms' => 0, 'title' => 'Only one']], $payload['chapters']);
        $this->assertTrue($payload['can_edit_chapters']);
        $this->assertSame(3_600_500, $payload['video_duration_ms']);
        $this->assertSame('30000/1001', $payload['video_frame_rate']);
        $this->assertSame("/api/media/by-ulid/{$media->ulid}/chapters.vtt", $payload['chapters_vtt_url']);

        $this->putChapters($owner, $media, [])->assertOk()->assertJsonPath('data', []);
        $this->assertSame(0, $media->chapters()->count());
    }

    public function test_viewers_see_chapters_but_cannot_edit_them(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $media = $this->video($owner);
        $this->putChapters($owner, $media, [['start_ms' => 0, 'title' => 'Start']])->assertOk();

        $payload = $this->actingAs($viewer)->get("/m/{$media->ulid}")->assertOk()->viewData('initialData')['mediaView'];
        $this->assertSame([['start_ms' => 0, 'title' => 'Start']], $payload['chapters']);
        $this->assertFalse($payload['can_edit_chapters']);
        $this->assertArrayNotHasKey('user_id', $payload['chapters'][0]);

        $this->putChapters($viewer, $media, [['start_ms' => 0, 'title' => 'Hijacked']])->assertForbidden();
        $this->assertSame(['Start'], $media->chapters()->pluck('title')->all());
    }

    public function test_hidden_videos_answer_404_for_chapters(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $pending = Media::factory()->for($owner)->video()->create(['disk' => 's3']);

        $this->putChapters($viewer, $pending, [['start_ms' => 0, 'title' => 'x']])->assertNotFound();
        $this->actingAs($viewer)->get("/api/media/by-ulid/{$pending->ulid}/chapters.vtt")->assertNotFound();
        $this->actingAs($viewer)->get('/api/media/by-ulid/01NOSUCHMEDIA/chapters.vtt')->assertNotFound();
    }

    public function test_chapter_lists_are_validated(): void
    {
        $owner = User::factory()->approved()->create();
        $media = $this->video($owner, ['durationSeconds' => 60]);

        $cases = [
            'first not at zero' => [[['start_ms' => 1000, 'title' => 'Late']], 'chapters'],
            'duplicate starts' => [[['start_ms' => 0, 'title' => 'A'], ['start_ms' => 0, 'title' => 'B']], 'chapters.0.start_ms'],
            'empty title' => [[['start_ms' => 0, 'title' => '']], 'chapters.0.title'],
            'long title' => [[['start_ms' => 0, 'title' => str_repeat('x', 121)]], 'chapters.0.title'],
            'multi-line title' => [[['start_ms' => 0, 'title' => "One\n\nTwo"]], 'chapters.0.title'],
            'negative start' => [[['start_ms' => -1, 'title' => 'A']], 'chapters.0.start_ms'],
            'unknown key' => [[['start_ms' => 0, 'title' => 'A', 'user_id' => 1]], 'chapters.0'],
            'past the end' => [[['start_ms' => 0, 'title' => 'A'], ['start_ms' => 60_000, 'title' => 'B']], 'chapters.1.start_ms'],
        ];
        foreach ($cases as $name => [$chapters, $field]) {
            $response = $this->putChapters($owner, $media, $chapters);
            $this->assertSame(422, $response->status(), "{$name}: ".$response->getContent());
            $response->assertJsonValidationErrors($field);
        }

        $tooMany = array_map(fn (int $i): array => ['start_ms' => $i * 100, 'title' => "C{$i}"], range(0, VideoChapter::MAX_PER_VIDEO));
        $this->putChapters($owner, $media, $tooMany)->assertUnprocessable()->assertJsonValidationErrors('chapters');
        $this->actingAs($owner)->putJson("/api/media/by-ulid/{$media->ulid}/chapters", [])->assertUnprocessable();
        $this->assertSame(0, $media->chapters()->count());
    }

    public function test_photos_have_no_chapters(): void
    {
        $owner = User::factory()->approved()->create();
        $photo = Media::factory()->for($owner)->approved()->create();

        $this->putChapters($owner, $photo, [['start_ms' => 0, 'title' => 'A']])->assertForbidden();
        $this->actingAs($owner)->get("/api/media/by-ulid/{$photo->ulid}/chapters.vtt")->assertNotFound();
        $payload = $this->actingAs($owner)->get("/m/{$photo->ulid}")->viewData('initialData')['mediaView'];
        $this->assertArrayNotHasKey('chapters', $payload);
    }

    public function test_chapters_are_served_as_an_escaped_webvtt_track(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $media = $this->video($owner, ['durationSeconds' => 3600]);
        $this->putChapters($owner, $media, [
            ['start_ms' => 0, 'title' => 'Opening <b>& friends</b>'],
            ['start_ms' => 751_250, 'title' => 'Cake --> candles'],
        ])->assertOk();

        $response = $this->actingAs($viewer)->get("/api/media/by-ulid/{$media->ulid}/chapters.vtt")->assertOk();

        $this->assertStringStartsWith('text/vtt', (string) $response->headers->get('Content-Type'));
        $this->assertSame(implode("\n", [
            'WEBVTT',
            '',
            '1',
            '00:00:00.000 --> 00:12:31.250',
            'Opening &lt;b&gt;&amp; friends&lt;/b&gt;',
            '',
            '2',
            '00:12:31.250 --> 01:00:00.000',
            'Cake --&gt; candles',
            '',
        ]), $response->getContent());
    }

    public function test_video_metadata_is_cached_once_and_tolerates_missing_files(): void
    {
        $owner = User::factory()->approved()->create();
        $media = $this->video($owner, ['durationSeconds' => 12.25, 'frameRate' => '25/1']);
        $this->actingAs($owner)->get("/m/{$media->ulid}")->assertOk();
        $media->refresh();
        $this->assertSame(12_250, $media->video_duration_ms);
        $this->assertSame('25/1', $media->video_frame_rate);
        $this->assertNotNull($media->video_metadata_checked_at);

        Storage::disk('hls')->put('by-id/sha256:abc/metadata.json', json_encode(['source' => ['durationSeconds' => 99, 'frameRate' => '30/1']]));
        $this->actingAs($owner)->get("/m/{$media->ulid}")->assertOk();
        $this->assertSame('25/1', $media->refresh()->video_frame_rate, 'a known frame rate is not re-read');

        $bare = $this->video($owner, ['durationSeconds' => 'soon', 'frameRate' => '0/0']);
        $payload = $this->actingAs($owner)->get("/m/{$bare->ulid}")->viewData('initialData')['mediaView'];
        $this->assertNull($payload['video_duration_ms']);
        $this->assertNull($payload['video_frame_rate']);
    }

    public function test_chapters_survive_anonymized_when_only_library_saves_keep_the_video(): void
    {
        $owner = User::factory()->approved()->create();
        $saver = User::factory()->approved()->create();
        $media = $this->video($owner);
        $this->putChapters($owner, $media, [['start_ms' => 0, 'title' => 'Start'], ['start_ms' => 5000, 'title' => 'Cake']])->assertOk();
        $saver->libraryItems()->create(['source' => 'saved', 'media_id' => $media->id]);

        app(MediaService::class)->delete($media);

        $rows = VideoChapter::query()->where('media_id', $media->id)->orderBy('start_ms')->get();
        $this->assertSame(['Start', 'Cake'], $rows->pluck('title')->all());
        $this->assertSame([null, null], $rows->pluck('user_id')->all());
        $this->assertSame([null, null], $rows->pluck('character_id')->all());

        $saver->libraryItems()->delete();
        app(MediaService::class)->delete(Media::withTrashed()->findOrFail($media->id));
        $this->assertSame(0, VideoChapter::query()->where('media_id', $media->id)->count());
    }
}
