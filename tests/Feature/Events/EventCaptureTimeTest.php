<?php

namespace Tests\Feature\Events;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Models\Event;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventCaptureTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['instance.modules.events' => true, 'instance.modules.library' => true]);
        Notification::fake();
        Storage::fake('photos');
        Storage::fake('s3');
        $this->partialMock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getSignedUploadUrl')->andReturn(['url' => 'https://example.test/upload', 'headers' => []]);
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://example.test/view');
        });
    }

    private function account(): User
    {
        return User::factory()->approved()->create(['library_enabled' => true]);
    }

    private function event(User $member): Event
    {
        $event = Event::create(['name' => 'Reception', 'visibility' => 'specific', 'timezone' => 'America/Los_Angeles']);
        $event->members()->attach($member->id);

        return $event;
    }

    public function test_uploads_record_capture_time_in_the_event_time_zone_and_drop_unusable_values(): void
    {
        CarbonImmutable::setTestNow('2026-10-26T12:00:00Z');
        $owner = $this->account();
        $event = $this->event($owner);
        $url = '/api/events/'.$event->ulid.'/uploads';
        $photo = ['type' => 'photo', 'filename' => 'photo.jpg', 'content_type' => 'image/jpeg', 'size' => 8];
        $upload = fn (array $payload): Media => Media::findOrFail($this->actingAs($owner)->postJson($url, $payload)->assertCreated()->json('data.id'));

        $offset = $upload([...$photo, 'captured_at' => '2026-10-24T17:32:05-04:00']);
        $this->assertSame('2026-10-24 21:32:05', $offset->captured_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($offset->gallery_sort_at->equalTo($offset->captured_at));

        $wallClock = $upload([...$photo, 'filename' => 'local.jpg', 'captured_at' => '2026-10-24T17:32:05']);
        $this->assertSame('2026-10-25 00:32:05', $wallClock->captured_at->utc()->format('Y-m-d H:i:s'));

        $video = $upload(['type' => 'video', 'filename' => 'clip.mov', 'content_type' => 'video/quicktime', 'size' => 8, 'captured_at' => '2026-10-24T23:00:00Z']);
        $this->assertSame('2026-10-24 23:00:00', $video->captured_at->utc()->format('Y-m-d H:i:s'));

        $junk = $upload([...$photo, 'filename' => 'junk.jpg', 'captured_at' => '2026:10:24 17:32:05']);
        $this->assertNull($junk->captured_at);
        $this->assertSame('2026-10-26 12:00:00', $junk->gallery_sort_at->utc()->format('Y-m-d H:i:s'));

        $this->postJson($url, [...$photo, 'captured_at' => str_repeat('9', 41)])->assertUnprocessable()->assertJsonValidationErrors('captured_at');
        CarbonImmutable::setTestNow();
    }

    public function test_gallery_lists_by_capture_time_with_upload_time_fallback_or_by_recent_upload(): void
    {
        $owner = $this->account();
        $event = $this->event($owner);
        $make = fn (string $title, string $created, ?string $captured): Media => Media::factory()->for($owner)->approved()->create([
            'title' => $title, 'purpose' => MediaPurpose::Event, 'event_id' => $event->id, 'audience' => Audience::Everyone,
            'discoverable' => false, 'created_at' => $created, 'captured_at' => $captured,
        ]);
        // Uploaded in a different order from the one the moments happened in.
        $make('Speeches', '2026-10-25 09:00:00', '2026-10-24 20:00:00');
        $make('Ceremony', '2026-10-25 10:00:00', '2026-10-24 16:00:00');
        $make('Screenshot without EXIF', '2026-10-24 18:00:00', null);
        $make('First dance', '2026-10-25 08:00:00', '2026-10-24 21:00:00');

        $titles = fn (string $query): array => array_column($this->actingAs($owner)->getJson('/api/events/media'.$query)->assertOk()->json('data'), 'title');
        $this->assertSame(['Ceremony', 'Screenshot without EXIF', 'Speeches', 'First dance'], $titles(''));
        $this->assertSame(['Ceremony', 'Screenshot without EXIF', 'Speeches', 'First dance'], $titles('?sort=taken'));
        $this->assertSame(['Ceremony', 'Speeches', 'First dance', 'Screenshot without EXIF'], $titles('?sort=recent'));
        $this->getJson('/api/events/media?sort=random')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }
}
