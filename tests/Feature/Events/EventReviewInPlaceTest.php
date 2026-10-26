<?php

namespace Tests\Feature\Events;

use App\Enums\MediaPurpose;
use App\Enums\ModerationStatus;
use App\Models\Event;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventReviewInPlaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $guest;

    private User $uploader;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        config(['instance.modules.events' => true, 'instance.modules.library' => true]);
        Notification::fake();
        Storage::fake('photos');
        Storage::fake('s3');
        Storage::fake('hls');
        $this->partialMock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://storage.example.test/view');
            $mock->shouldReceive('getSignedDownloadUrl')->andReturn('https://storage.example.test/original');
        });
        $this->admin = User::factory()->admin()->approved()->create();
        $this->guest = User::factory()->approved()->create();
        $this->uploader = User::factory()->approved()->create();
        $this->event = Event::create(['name' => 'Reception', 'visibility' => 'everyone', 'timezone' => 'UTC']);
    }

    private function media(array $attributes = []): Media
    {
        return Media::factory()->for($this->uploader)->create([
            'purpose' => MediaPurpose::Event, 'event_id' => $this->event->id, 'discoverable' => false,
            'upload_status' => 'ready', 'moderation_status' => ModerationStatus::Pending, 'size_bytes' => 8, ...$attributes,
        ]);
    }

    public function test_only_admins_get_the_queue_and_it_stays_out_of_the_ordinary_gallery(): void
    {
        $pending = $this->media(['title' => 'Pending toast']);
        $this->media(['title' => 'Still uploading', 'upload_status' => 'pending']);
        $this->media(['title' => 'Shared dance', 'moderation_status' => ModerationStatus::Approved]);

        $this->actingAs($this->guest)->getJson('/api/events/media?review=1')->assertForbidden();
        $this->getJson('/api/events/settings')->assertOk()->assertJsonPath('data.pending_review_count', null);
        $this->getJson('/api/events/media/'.$pending->ulid)->assertNotFound();

        $this->actingAs($this->admin)->getJson('/api/events/settings')->assertOk()->assertJsonPath('data.pending_review_count', 1);
        $queue = $this->getJson('/api/events/media?review=1')->assertOk()->assertJsonCount(1, 'data');
        $queue->assertJsonPath('data.0.title', 'Pending toast')->assertJsonPath('data.0.review.pending', true)
            ->assertJsonPath('data.0.thumbnail_url', fn ($url) => $url === null || str_starts_with($url, 'https://storage.example.test/'));
        $gallery = array_column($this->getJson('/api/events/media')->assertOk()->json('data'), 'title');
        $this->assertSame(['Shared dance'], $gallery);
    }

    public function test_admin_detail_carries_review_data_and_heic_originals_need_a_check(): void
    {
        $jpeg = $this->media(['mime_type' => 'image/jpeg']);
        $heic = $this->media(['mime_type' => 'image/heic', 'original_filename' => 'IMG_0001.HEIC']);
        $shared = $this->media(['moderation_status' => ModerationStatus::Approved]);

        $this->actingAs($this->admin)->getJson('/api/events/media/'.$jpeg->ulid)->assertOk()
            ->assertJsonPath('data.review.pending', true)->assertJsonPath('data.review.requires_original_check', false)
            ->assertJsonPath('data.review.original_url', null)->assertJsonPath('data.url', 'https://storage.example.test/view');
        $this->getJson('/api/events/media/'.$heic->ulid)->assertOk()
            ->assertJsonPath('data.review.requires_original_check', true)->assertJsonPath('data.review.original_url', 'https://storage.example.test/original')
            ->assertJsonPath('data.review.original_size_bytes', 8)->assertJsonPath('data.review.original_filename', 'IMG_0001.HEIC');
        $this->getJson('/api/events/media/'.$shared->ulid)->assertOk()->assertJsonPath('data.review', null);

        // Approval goes through the existing admin moderation endpoint and leaves the queue.
        $this->postJson('/api/admin/media/'.$jpeg->id.'/moderate', ['action' => 'reject', 'notes' => 'Blurry'])->assertOk();
        $this->getJson('/api/events/settings')->assertJsonPath('data.pending_review_count', 1);
        $this->assertSame([$heic->ulid], array_column($this->getJson('/api/events/media?review=1')->json('data'), 'ulid'));
    }
}
