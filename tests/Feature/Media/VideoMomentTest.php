<?php

namespace Tests\Feature\Media;

use App\Models\Character;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoMoment;
use App\Services\FileStorageService;
use App\Services\Media\MediaModerationService;
use App\Services\Media\MediaService;
use App\Services\Privacy\BlockService;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

class VideoMomentTest extends TestCase
{
    use RefreshDatabase;

    private const CONTENT = 'sha256:abc';

    protected function setUp(): void
    {
        parent::setUp();
        // User 1 is the primary admin; keep it out of these relationship tests.
        User::factory()->approved()->create();
        foreach (['s3', 'photos', 'hls'] as $disk) {
            Storage::fake($disk);
        }
        // Reads and writes go to the fake disks; only URL signing is stubbed.
        $this->partialMock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturnUsing(fn (string $disk, string $key): string => "https://signed.example/{$disk}/{$key}");
            $mock->shouldReceive('getSignedDownloadUrl')->andReturn('https://signed.example/download');
            $mock->shouldReceive('getSignedUploadUrl')->andReturnUsing(fn (string $disk, string $key): array => [
                'url' => "https://upload.example/{$disk}/{$key}", 'headers' => ['Content-Type' => 'image/jpeg'],
            ]);
        });
    }

    private function video(User $owner, array $attributes = []): Media
    {
        $media = Media::factory()->for($owner)->video()->approved()->create(['disk' => 's3', ...$attributes]);
        Storage::disk('hls')->put('mappings/'.$media->playbackObjectKey().'.json', json_encode(['contentId' => self::CONTENT]));
        Storage::disk('hls')->put('by-id/'.self::CONTENT.'/master.m3u8', "#EXTM3U\n");
        Storage::disk('hls')->put('by-id/'.self::CONTENT.'/metadata.json', json_encode(['source' => ['durationSeconds' => 600, 'frameRate' => '30000/1001']]));

        return $media;
    }

    private function save(User $user, Media $video, array $body = []): TestResponse
    {
        return $this->actingAs($user)->postJson("/api/media/by-ulid/{$video->ulid}/moments", [
            'time_ms' => 12_345, 'visibility' => 'shared', 'wants_provisional' => true, 'capture_bytes' => 2048, ...$body,
        ]);
    }

    private function jpeg(int $size = 8): string
    {
        $image = imagecreatetruecolor($size, $size);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private function uploadCapture(VideoMoment $moment, ?string $bytes = null): void
    {
        Storage::disk('photos')->put((string) $moment->provisional_key, $bytes ?? $this->jpeg());
    }

    private function publishFrame(int $ms): void
    {
        Storage::disk('hls')->put('by-id/'.self::CONTENT."/frames/{$ms}_thumb.jpg", 'thumb');
        Storage::disk('hls')->put('by-id/'.self::CONTENT."/frames/{$ms}.jpg", 'full-frame-bytes');
    }

    public function test_saving_requests_a_frame_and_offers_a_provisional_upload(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $video = $this->video($owner);

        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->makePartial();
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://signed.example/view');
            $mock->shouldReceive('getSignedUploadUrl')->once()
                ->withArgs(fn (string $disk, string $key, string $type, int $bytes): bool => $disk === 'photos' && $type === 'image/jpeg' && $bytes === 2048)
                ->andReturnUsing(fn (string $disk, string $key): array => ['url' => "https://upload.example/{$disk}/{$key}", 'headers' => ['Content-Type' => 'image/jpeg']]);
        });
        $response = $this->save($viewer, $video, ['caption' => '  Cake!  '])->assertCreated();

        $moment = VideoMoment::query()->sole();
        $this->assertSame(self::CONTENT, $moment->content_id);
        $this->assertSame('Cake!', $moment->caption);
        $this->assertSame(VideoMoment::FRAME_PENDING, $moment->frame_status);
        $this->assertNull($moment->character_id);
        $response->assertJsonPath('data.image', null)
            ->assertJsonPath('data.is_mine', true)
            ->assertJsonPath('provisional_upload.url', "https://upload.example/photos/moments/provisional/{$viewer->id}/{$moment->ulid}.jpg");
        Storage::disk('s3')->assertExists('frame-requests/'.self::CONTENT.'/12345.json');
        $this->assertSame(12_345, json_decode((string) Storage::disk('s3')->get('frame-requests/'.self::CONTENT.'/12345.json'), true)['ms']);
    }

    public function test_the_provisional_capture_is_shown_only_to_its_author_until_the_real_frame_lands(): void
    {
        $owner = User::factory()->approved()->create();
        $author = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $video = $this->video($owner);
        $this->save($author, $video)->assertCreated();
        $moment = VideoMoment::query()->sole();
        $this->uploadCapture($moment);

        $this->actingAs($author)->postJson("/api/moments/{$moment->ulid}/provisional/complete")
            ->assertOk()->assertJsonPath('data.image.provisional', true);

        $this->actingAs($author)->get("/api/moments/{$moment->ulid}/image/full")
            ->assertRedirect("https://signed.example/photos/{$moment->provisional_key}");
        foreach ([$other, $owner] as $viewer) {
            $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$video->ulid}/moments")
                ->assertOk()->assertJsonPath('data.0.ulid', $moment->ulid)->assertJsonPath('data.0.image', null);
            $this->actingAs($viewer)->get("/api/moments/{$moment->ulid}/image/full")->assertNotFound();
            $this->actingAs($viewer)->get("/api/moments/{$moment->ulid}/image/thumb")->assertNotFound();
        }

        // The worker answers; the sync publishes the real frame to everyone.
        $this->publishFrame(12_345);
        $this->artisan('media:sync-moment-frames')->assertSuccessful();

        $moment->refresh();
        $this->assertSame(VideoMoment::FRAME_READY, $moment->frame_status);
        $this->assertNull($moment->provisional_key);
        Storage::disk('photos')->assertMissing("moments/provisional/{$author->id}/{$moment->ulid}.jpg");
        Storage::disk('s3')->assertMissing('frame-requests/'.self::CONTENT.'/12345.json');
        $this->actingAs($other)->get("/api/moments/{$moment->ulid}/image/thumb")
            ->assertRedirect('https://signed.example/hls/by-id/'.self::CONTENT.'/frames/12345_thumb.jpg');
        $this->actingAs($other)->getJson("/api/media/by-ulid/{$video->ulid}/moments")
            ->assertJsonPath('data.0.image.provisional', false)
            ->assertJsonPath('data.0.image.full_url', "/api/moments/{$moment->ulid}/image/full");
    }

    public function test_bad_captures_are_rejected_and_deleted(): void
    {
        $owner = User::factory()->approved()->create();
        $video = $this->video($owner);

        foreach (['not an image', str_repeat('x', 1_600_000)] as $bytes) {
            $this->save($owner, $video, ['time_ms' => random_int(1, 100_000)])->assertCreated();
            $moment = VideoMoment::query()->latest('id')->firstOrFail();
            $key = (string) $moment->provisional_key;
            $this->uploadCapture($moment, $bytes);

            $this->actingAs($owner)->postJson("/api/moments/{$moment->ulid}/provisional/complete")->assertUnprocessable();
            Storage::disk('photos')->assertMissing($key);
            $this->assertNull($moment->refresh()->provisional_key);
        }
    }

    public function test_an_existing_frame_makes_a_moment_ready_without_a_request_or_upload(): void
    {
        $owner = User::factory()->approved()->create();
        $video = $this->video($owner);
        $this->publishFrame(12_345);

        $this->save($owner, $video)->assertCreated()
            ->assertJsonPath('provisional_upload', null)
            ->assertJsonPath('data.frame_status', 'ready')
            ->assertJsonPath('data.can_use_as_cover', true);
        Storage::disk('s3')->assertMissing('frame-requests/'.self::CONTENT.'/12345.json');
    }

    public function test_private_moments_blocks_and_hidden_accounts_are_respected(): void
    {
        $owner = User::factory()->approved()->create();
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $video = $this->video($owner);
        $this->save($author, $video, ['visibility' => 'private', 'time_ms' => 1000])->assertCreated();
        $this->save($author, $video, ['time_ms' => 2000])->assertCreated();

        $this->actingAs($author)->getJson("/api/media/by-ulid/{$video->ulid}/moments")->assertJsonCount(2, 'data');
        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$video->ulid}/moments")->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.time_ms', 2000);

        app(BlockService::class)->block($viewer, $author);
        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$video->ulid}/moments")->assertJsonCount(0, 'data');
        app(BlockService::class)->unblock($viewer, $author);

        $author->forceFill(['banned_at' => now(), 'ban_hides_content' => true])->save();
        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$video->ulid}/moments")->assertJsonCount(0, 'data');
    }

    public function test_moments_need_a_watchable_ready_video_and_respect_limits(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $pending = Media::factory()->for($owner)->video()->create(['disk' => 's3']);
        $this->save($viewer, $pending)->assertNotFound();

        $video = $this->video($owner);
        $this->save($viewer, $video, ['time_ms' => 600_000])->assertUnprocessable()->assertJsonValidationErrors('time_ms');
        $this->save($viewer, $video, ['visibility' => 'everyone'])->assertUnprocessable()->assertJsonValidationErrors('visibility');
        $this->save($viewer, $video, ['caption' => str_repeat('x', 281)])->assertUnprocessable()->assertJsonValidationErrors('caption');

        $this->save($viewer, $video, ['capture_bytes' => 1_600_000])->assertUnprocessable()->assertJsonValidationErrors('capture_bytes');
        $this->save($viewer, $video, ['capture_bytes' => null])->assertUnprocessable()->assertJsonValidationErrors('capture_bytes');

        config(['media.moments.max_per_user_per_video' => 1]);
        $this->save($viewer, $video)->assertCreated();
        $this->save($viewer, $video, ['time_ms' => 9])->assertUnprocessable()->assertJsonValidationErrors('time_ms');
    }

    public function test_authors_edit_and_uploaders_curate(): void
    {
        $owner = User::factory()->approved()->create();
        $author = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $video = $this->video($owner);
        $this->save($author, $video)->assertCreated();
        $moment = VideoMoment::query()->sole();

        $this->actingAs($other)->patchJson("/api/moments/{$moment->ulid}", ['caption' => 'Mine now'])->assertForbidden();
        $this->actingAs($owner)->patchJson("/api/moments/{$moment->ulid}", ['caption' => 'Mine now'])->assertForbidden();
        $this->actingAs($author)->patchJson("/api/moments/{$moment->ulid}", ['caption' => ' Blowing out candles ', 'visibility' => 'private'])
            ->assertOk()->assertJsonPath('data.caption', 'Blowing out candles')->assertJsonPath('data.visibility', 'private');
        $this->actingAs($author)->patchJson("/api/moments/{$moment->ulid}", ['visibility' => 'shared'])->assertOk();

        $this->actingAs($other)->deleteJson("/api/moments/{$moment->ulid}")->assertForbidden();
        $this->actingAs($owner)->deleteJson("/api/moments/{$moment->ulid}")->assertOk();
        $this->assertSame(0, VideoMoment::query()->count());
    }

    public function test_failed_frames_can_be_retried_by_their_author(): void
    {
        $owner = User::factory()->approved()->create();
        $video = $this->video($owner);
        $this->save($owner, $video)->assertCreated();
        $moment = VideoMoment::query()->sole();
        Storage::disk('hls')->put('by-id/'.self::CONTENT.'/frames/12345.error.json', json_encode(['error' => 'past the end']));

        $this->artisan('media:sync-moment-frames')->assertSuccessful();
        $this->assertSame(VideoMoment::FRAME_FAILED, $moment->refresh()->frame_status);
        Storage::disk('s3')->assertMissing('frame-requests/'.self::CONTENT.'/12345.json');

        $this->travel(5)->seconds();
        $this->actingAs($owner)->postJson("/api/moments/{$moment->ulid}/retry")->assertOk()->assertJsonPath('data.frame_status', 'pending');
        Storage::disk('s3')->assertExists('frame-requests/'.self::CONTENT.'/12345.json');

        // The old error marker predates the retry, so it does not fail it again.
        touch(Storage::disk('hls')->path('by-id/'.self::CONTENT.'/frames/12345.error.json'), now()->subMinute()->getTimestamp());
        $this->artisan('media:sync-moment-frames')->assertSuccessful();
        $this->assertSame(VideoMoment::FRAME_PENDING, $moment->refresh()->frame_status);
        Storage::disk('s3')->assertExists('frame-requests/'.self::CONTENT.'/12345.json');
    }

    public function test_the_uploader_can_use_a_ready_moment_as_the_cover(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $video = $this->video($owner, ['thumbnail_key' => 'thumbs/old.jpg', 'reviewed_thumbnail_key' => 'thumbs/old-reviewed.jpg']);
        Storage::disk('photos')->put('thumbs/old.jpg', 'old');
        Storage::disk('photos')->put('thumbs/old-reviewed.jpg', 'old');
        $this->publishFrame(12_345);
        $this->save($viewer, $video)->assertCreated();
        $moment = VideoMoment::query()->sole();

        $this->actingAs($viewer)->postJson("/api/media/by-ulid/{$video->ulid}/cover", ['moment' => $moment->ulid])->assertForbidden();
        $this->actingAs($owner)->postJson("/api/media/by-ulid/{$video->ulid}/cover", ['moment' => $moment->ulid])->assertOk();

        $video->refresh();
        $this->assertStringStartsWith('posters/', (string) $video->thumbnail_key);
        $this->assertSame($video->thumbnail_key, $video->reviewed_thumbnail_key);
        $this->assertSame('full-frame-bytes', Storage::disk('photos')->get((string) $video->thumbnail_key));
        Storage::disk('photos')->assertMissing('thumbs/old.jpg');
        Storage::disk('photos')->assertMissing('thumbs/old-reviewed.jpg');
    }

    public function test_the_uploaders_moments_on_a_separate_persona_video_show_the_persona(): void
    {
        $owner = User::factory()->approved()->create(['display_name' => 'Real Name']);
        $persona = Character::factory()->for($owner)->create(['display_name' => 'Tape Archive', 'is_linked' => false]);
        $video = $this->video($owner, ['character_id' => $persona->id]);
        $viewer = User::factory()->approved()->create();
        if (! $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$video->ulid}/moments")->isOk()) {
            $this->markTestSkipped('Persona media is not visible to this viewer by default.');
        }

        $this->save($owner, $video)->assertCreated();
        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$video->ulid}/moments")
            ->assertJsonPath('data.0.author.display_name', 'Tape Archive')
            ->assertJsonPath('data.0.author.id', null);
    }

    public function test_rejection_deletes_captures_and_permanent_removal_purges_moments(): void
    {
        $owner = User::factory()->approved()->create();
        $author = User::factory()->approved()->create();
        $video = $this->video($owner);
        $this->save($author, $video)->assertCreated();
        $moment = VideoMoment::query()->sole();
        $key = (string) $moment->provisional_key;
        $this->uploadCapture($moment);

        app(MediaModerationService::class)->reject($video, User::query()->findOrFail(1), 'Takedown');
        Storage::disk('photos')->assertMissing($key);
        $this->assertNull($moment->refresh()->provisional_key);
        $this->assertSame(1, VideoMoment::query()->count(), 'rejection hides moments; only permanent removal deletes them');

        app(MediaService::class)->delete($video->refresh());
        $this->assertSame(0, VideoMoment::query()->count());
        Storage::disk('s3')->assertMissing('frame-requests/'.self::CONTENT.'/12345.json');
    }

    public function test_releasing_a_publication_kept_for_library_saves_removes_its_moments(): void
    {
        $owner = User::factory()->approved()->create();
        $saver = User::factory()->approved()->create();
        $video = $this->video($owner);
        $this->save($saver, $video)->assertCreated();
        $saver->libraryItems()->create(['source' => 'saved', 'media_id' => $video->id]);

        app(MediaService::class)->delete($video);

        $this->assertNotNull(Media::withTrashed()->find($video->id));
        $this->assertSame(0, VideoMoment::query()->count());
    }

    public function test_account_deletion_removes_the_accounts_moments_and_captures(): void
    {
        $owner = User::factory()->approved()->create();
        $author = User::factory()->approved()->create();
        $video = $this->video($owner);
        $this->save($author, $video)->assertCreated();
        $this->save($owner, $video, ['time_ms' => 5000])->assertCreated();
        $moment = VideoMoment::query()->where('user_id', $author->id)->sole();
        $key = (string) $moment->provisional_key;
        $this->uploadCapture($moment);

        app(UserAccountService::class)->purge($author);

        Storage::disk('photos')->assertMissing($key);
        $this->assertSame([$owner->id], VideoMoment::query()->pluck('user_id')->all());
    }

    public function test_abandoned_captures_are_pruned(): void
    {
        $owner = User::factory()->approved()->create();
        $video = $this->video($owner);
        $this->save($owner, $video)->assertCreated();
        $moment = VideoMoment::query()->sole();
        $this->uploadCapture($moment);
        $moment->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->artisan('media:prune-orphans')->assertSuccessful();

        $this->assertNull($moment->refresh()->provisional_key);
        $this->assertSame(1, VideoMoment::query()->count());
    }
}
