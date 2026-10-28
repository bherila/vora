<?php

namespace Tests\Feature\Media;

use App\Enums\MediaPurpose;
use App\Models\Event;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\Media\MediaModerationService;
use App\Services\SettingsService;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PreReviewVideoProcessingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        config(['instance.modules.events' => true, 'media.reviewed_key_prefix' => 'videos']);
        $this->admin = User::factory()->admin()->create(['id' => 1]);
        $this->event = Event::create(['name' => 'Wedding', 'visibility' => 'everyone', 'timezone' => 'UTC']);
        Storage::fake('s3');
        Storage::fake('photos');
        Storage::fake('hls');
        $this->partialMock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://storage.example.test/view');
            $mock->shouldReceive('getSignedDownloadUrl')->andReturn('https://storage.example.test/download');
        });
    }

    private function upload(User $owner, bool $video = true, string $state = 'ready'): Media
    {
        $factory = Media::factory()->for($owner);
        $factory = $video ? $factory->video() : $factory;
        $factory = match ($state) {
            'uploading' => $factory->pendingUpload(),
            'approved' => $factory->approved(),
            default => $factory,
        };
        $media = $factory->create(['purpose' => MediaPurpose::Event, 'event_id' => $this->event->id, 'discoverable' => false]);
        Storage::disk($media->disk)->put($media->object_key, 'original bytes');

        return $media;
    }

    private function prepare(): void
    {
        $this->artisan('media:prepare-videos-for-review')->assertSuccessful();
    }

    public function test_videos_awaiting_review_are_transcoded_from_the_copy_approval_publishes(): void
    {
        $owner = User::factory()->approved()->create();
        $video = $this->upload($owner);
        $photo = $this->upload($owner, video: false);
        $uploading = $this->upload($owner, state: 'uploading');
        $approved = $this->upload($owner, state: 'approved');

        // Off by default: nothing is copied for the transcoder before approval.
        $this->prepare();
        $this->assertNull($video->fresh()->reviewed_object_key);

        $this->actingAs($this->admin)->getJson('/api/admin/media')
            ->assertJsonPath('settings.pre_review_video_processing', false);
        $this->actingAs($this->admin)->putJson('/api/admin/media-settings', ['pre_review_video_processing' => true])
            ->assertOk()->assertJsonPath('data.pre_review_video_processing', true);
        $this->prepare();

        $video->refresh();
        $reviewed = 'videos/'.$owner->id.'/'.$video->ulid.'.mp4';
        $this->assertTrue($video->isPendingReview());
        $this->assertSame($reviewed, $video->reviewed_object_key);
        $this->assertSame($reviewed, $video->playbackObjectKey());
        $this->assertSame('original bytes', Storage::disk('s3')->get($reviewed));
        foreach ([$photo, $uploading, $approved] as $untouched) {
            $this->assertNull($untouched->fresh()->reviewed_object_key);
        }

        // The transcoder finished: the uploader and administrators can play the
        // pending video; other attendees still cannot see it.
        Storage::disk('hls')->put('mappings/'.$reviewed.'.json', json_encode(['contentId' => 'sha256:abc']));
        Storage::disk('hls')->put('by-id/sha256:abc/master.m3u8', "#EXTM3U\n720p/index.m3u8\n");
        $this->actingAs($this->admin)->get("/api/media/{$video->id}/hls/master.m3u8")->assertOk();
        $this->actingAs($owner)->get("/api/media/{$video->id}/hls/master.m3u8")->assertOk();
        $attendee = User::factory()->approved()->create();
        $this->actingAs($attendee)->get("/api/media/{$video->id}/hls/master.m3u8")->assertNotFound();

        // The upload URL could still replace the original after preparation;
        // approval publishes the prepared bytes and keeps the finished stream.
        Storage::disk('s3')->put($video->object_key, 'replaced after preparation');
        $this->actingAs($this->admin)->postJson("/api/admin/media/{$video->id}/moderate", ['action' => 'approve'])->assertOk();
        $video->refresh();
        $this->assertTrue($video->isApprovedContent());
        $this->assertSame($reviewed, $video->playbackObjectKey());
        $this->assertSame('original bytes', Storage::disk('s3')->get($reviewed));
        $this->assertSame('sha256:abc', $video->hls_content_id);
        $this->actingAs($attendee)->get("/api/media/{$video->id}/hls/master.m3u8")->assertOk();
    }

    public function test_rejecting_a_prepared_video_deletes_the_copy(): void
    {
        app(SettingsService::class)->set(SettingsService::PRE_REVIEW_VIDEO_PROCESSING, true);
        $video = $this->upload(User::factory()->approved()->create());
        $this->prepare();
        $reviewed = $video->fresh()->reviewed_object_key;
        $this->assertNotNull($reviewed);

        $this->actingAs($this->admin)->postJson("/api/admin/media/{$video->id}/moderate", ['action' => 'reject'])->assertOk();

        Storage::disk('s3')->assertMissing($reviewed);
        $this->assertSame($video->object_key, $video->fresh()->playbackObjectKey());
        // A rejected video is not prepared again.
        $this->prepare();
        $this->assertNull($video->fresh()->reviewed_object_key);
    }

    public function test_rejection_deletes_a_copy_prepared_while_the_request_was_loading(): void
    {
        app(SettingsService::class)->set(SettingsService::PRE_REVIEW_VIDEO_PROCESSING, true);
        $video = $this->upload(User::factory()->approved()->create());
        // Preparation finishes just after the request bound its model.
        $prepared = false;
        Media::retrieved(function (Media $media) use (&$prepared, $video): void {
            if ($prepared || $media->id !== $video->id) {
                return;
            }
            $prepared = true;
            $this->assertTrue(app(MediaModerationService::class)->prepareForReview(Media::query()->findOrFail($media->id)));
        });

        $this->actingAs($this->admin)->postJson("/api/admin/media/{$video->id}/moderate", ['action' => 'reject'])->assertOk();

        $this->assertTrue($prepared);
        $this->assertNull($video->fresh()->reviewed_object_key);
        $this->assertSame([], array_values(array_filter(Storage::disk('s3')->allFiles(), fn (string $key): bool => str_starts_with($key, 'videos/'))));
    }

    public function test_only_administrators_change_the_setting_and_it_gates_the_schedule(): void
    {
        $this->actingAs(User::factory()->approved()->create())
            ->putJson('/api/admin/media-settings', ['pre_review_video_processing' => true])->assertForbidden();
        $this->actingAs($this->admin)->putJson('/api/admin/media-settings', [])->assertUnprocessable();

        $event = collect(app(Schedule::class)->events())
            ->sole(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'media:prepare-videos-for-review'));
        $this->assertFalse($event->filtersPass($this->app));
        app(SettingsService::class)->set(SettingsService::PRE_REVIEW_VIDEO_PROCESSING, true);
        $this->assertTrue($event->filtersPass($this->app));
    }
}
