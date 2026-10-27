<?php

namespace Tests\Feature\Events;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EventShareTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_installations_offer_a_share_target_for_photos_and_videos(): void
    {
        config(['instance.modules.events' => true]);
        $this->get('/manifest.webmanifest')->assertOk()
            ->assertJsonPath('start_url', '/events')
            ->assertJsonPath('share_target.action', '/events/share')
            ->assertJsonPath('share_target.method', 'POST')
            ->assertJsonPath('share_target.enctype', 'multipart/form-data')
            ->assertJsonPath('share_target.params.files.0', ['name' => 'media', 'accept' => ['image/*', 'video/*']]);

        config(['instance.modules.events' => false]);
        $this->get('/manifest.webmanifest')->assertOk()->assertJsonMissingPath('share_target');
    }

    public function test_a_share_the_service_worker_missed_returns_to_the_gallery_without_a_csrf_error(): void
    {
        config(['instance.modules.events' => true]);
        $user = User::factory()->create();
        // Android posts the share itself, so there is no CSRF token to send. The
        // forgery check is skipped under tests, so assert the exclusion directly.
        $this->assertContains(PreventRequestForgery::class, Route::getRoutes()->getByName('events.share')?->excludedMiddleware() ?? []);
        $this->actingAs($user)->post('/events/share', ['media' => [UploadedFile::fake()->image('IMG_0001.jpg')]])
            ->assertStatus(303)->assertRedirect('/events');

        config(['instance.modules.events' => false]);
        $this->post('/events/share')->assertNotFound();
    }
}
