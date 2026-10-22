<?php

namespace Tests\Feature;

use App\Enums\MediaPurpose;
use App\Models\Event;
use App\Models\Media;
use App\Models\User;
use App\Support\InstanceFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstanceProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_defaults_and_module_overrides_are_resolved_from_deployment_configuration(): void
    {
        $keys = ['APP_PROFILE', 'MODULE_EVENTS_ENABLED', 'MODULE_CHAT_ENABLED'];
        $previous = [];
        foreach ($keys as $key) {
            $previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        }
        $setEnvironment = static function (string $key, ?string $value): void {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }
        };

        try {
            $setEnvironment('MODULE_EVENTS_ENABLED', null);
            $setEnvironment('MODULE_CHAT_ENABLED', null);
            $setEnvironment('APP_PROFILE', 'event');
            $event = require config_path('instance.php');
            $this->assertSame('event', $event['profile']);
            $this->assertTrue($event['modules']['events']);
            $this->assertTrue($event['modules']['library']);
            $this->assertTrue($event['modules']['favorites']);
            foreach (['chat', 'community', 'personas', 'stories', 'profiles', 'interests'] as $module) {
                $this->assertFalse($event['modules'][$module]);
            }

            $setEnvironment('MODULE_CHAT_ENABLED', 'true');
            $override = require config_path('instance.php');
            $this->assertTrue($override['modules']['chat']);
            $setEnvironment('APP_PROFILE', 'vora');
            $setEnvironment('MODULE_CHAT_ENABLED', null);
            $vora = require config_path('instance.php');
            $this->assertFalse($vora['modules']['events']);
            $this->assertTrue($vora['modules']['chat']);
        } finally {
            foreach ($previous as $key => [$environment, $server, $process]) {
                unset($_ENV[$key], $_SERVER[$key]);
                if ($environment !== null) {
                    $_ENV[$key] = $environment;
                }
                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }
                putenv($process === false ? $key : $key.'='.$process);
            }
        }
    }

    public function test_disabled_modules_deny_pages_and_direct_apis_even_for_admins(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach (['community', 'chat', 'personas', 'stories', 'profiles', 'interests', 'favorites', 'library', 'events'] as $module) {
            config(['instance.modules.'.$module => false]);
        }

        foreach (['/feed', '/api/feed', '/messages', '/api/chat/conversations', '/personas/new', '/api/characters', '/api/c/missing/counts', '/stories', '/api/stories', '/me', '/api/users', '/api/media', '/interests/1', '/api/interests', '/api/favorites', '/api/library/items', '/events', '/api/events', '/api/admin/events'] as $path) {
            $method = in_array($path, ['/api/favorites', '/api/admin/events'], true) ? 'postJson' : 'getJson';
            $this->$method($path)->assertNotFound();
        }

        $this->postJson('/api/media', [])->assertNotFound();
        $this->get('/user/settings')->assertOk();
    }

    public function test_event_navigation_home_and_account_destinations_do_not_link_disabled_modules(): void
    {
        config(['instance.profile' => 'event', 'instance.modules' => array_fill_keys(['events', 'library', 'favorites'], true) + array_fill_keys(['community', 'chat', 'personas', 'stories', 'profiles', 'interests'], false)]);
        $user = User::factory()->approved()->create();
        $this->actingAs($user)->get('/')->assertRedirect('/events');
        $this->assertSame('/events', InstanceFeatures::homePath());
        $response = $this->get('/user/settings')->assertOk();
        preg_match('/<script id="initial-data"[^>]*>\s*(.*?)\s*<\/script>/s', $response->getContent(), $matches);
        $payload = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('event', $payload['instance']['profile']);
        $this->assertSame(['Events', 'Attendees'], array_column($payload['navbar']['navItems'], 'label'));
        $this->assertSame('/attendees/'.$user->id, $payload['navbar']['accountMenu']['profileHref']);
        $this->assertSame(['My collection', 'Settings', 'Invites', 'Log out'], array_column($payload['navbar']['accountMenu']['items'], 'label'));
        $this->assertSame([], $payload['navbar']['identities']);
    }

    public function test_event_guest_home_is_branded_for_event_media(): void
    {
        config(['instance.profile' => 'event']);
        $this->get('/')->assertOk()->assertSee('Share photos and videos from your events')->assertDontSee('creating, organizing, and sharing media, characters');
    }

    public function test_release_profile_check_fails_for_a_mismatched_server_configuration(): void
    {
        config(['instance.profile' => 'vora']);
        $this->artisan('ops:instance-profile', ['--expect' => 'event'])->assertFailed();
        $this->artisan('ops:instance-profile', ['--expect' => 'vora'])->assertSuccessful();
    }

    public function test_disabled_profiles_hide_legacy_gallery_paths_but_preserve_private_saves_and_admin_review(): void
    {
        Storage::fake('photos');
        Storage::fake('s3');
        Storage::fake('hls');
        $admin = User::factory()->approved()->admin()->create();
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create(['library_enabled' => true]);
        $photo = Media::factory()->for($owner)->approved()->create(['disk' => 'photos', 'reviewed_object_key' => 'reviewed/legacy.jpg']);
        $video = Media::factory()->for($owner)->video()->approved()->create(['disk' => 's3', 'hls_content_id' => 'legacy-gallery']);
        Storage::disk('photos')->put($photo->reviewed_object_key, 'retained-photo');
        Storage::disk('hls')->put('by-id/legacy-gallery/master.m3u8', "#EXTM3U\nstream/index.m3u8");
        $saved = $this->actingAs($viewer)->postJson('/api/library/save', ['media_ulid' => $photo->ulid])->assertCreated()->json('data.ulid');
        config(['instance.modules.profiles' => false]);

        foreach ([$owner, $viewer] as $user) {
            $this->actingAs($user)->get('/m/'.$photo->ulid)->assertNotFound();
            $this->getJson('/api/media/by-ulid/'.$photo->ulid)->assertNotFound();
            $this->getJson('/api/media/'.$photo->id)->assertNotFound();
            $this->get('/api/media/by-ulid/'.$photo->ulid.'/asset/original')->assertNotFound();
            $this->get('/api/media/'.$video->id.'/hls/master.m3u8')->assertNotFound();
            $this->assertFalse($photo->audienceAllows($user));
            $this->assertFalse($photo->isViewableBy($user));
            $this->assertFalse(Media::query()->viewableBy($user)->whereKey($photo->id)->exists());
        }
        $this->actingAs($owner)->patchJson('/api/media/'.$photo->id, ['title' => 'Hidden gallery edit'])->assertNotFound();
        $this->patchJson('/api/media/bulk', ['media_ids' => [$photo->id], 'title' => 'Hidden bulk edit'])->assertNotFound();
        $this->deleteJson('/api/media/bulk', ['media_ids' => [$photo->id]])->assertNotFound();
        $this->assertSame($photo->title, $photo->fresh()->title);
        $this->assertFalse(Media::query()->viewableBy(null)->whereKey($photo->id)->exists());
        $this->actingAs($viewer)->postJson('/api/favorites', ['type' => 'media', 'id' => $photo->id])->assertForbidden();
        $this->postJson('/api/library/save', ['media_ulid' => $video->ulid])->assertNotFound();
        $this->getJson('/api/library/items/'.$saved)->assertOk()->assertJsonPath('data.available', true);
        $this->get('/api/library/items/'.$saved.'/assets/original')->assertOk()->assertStreamedContent('retained-photo');
        $this->actingAs($admin)->get('/api/media/by-ulid/'.$photo->ulid.'/asset/original')->assertOk()->assertStreamedContent('retained-photo');
        $this->patchJson('/api/media/'.$photo->id, ['title' => str_repeat('x', 256)])->assertNotFound();
        $this->assertTrue(Gate::forUser($admin)->allows('view', $photo));
        $this->assertTrue(Media::query()->viewableBy($admin)->whereKey($photo->id)->exists());
    }

    public function test_disabled_profiles_preserve_event_media_and_core_profile_pictures(): void
    {
        Storage::fake('photos');
        User::factory()->approved()->admin()->create();
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $event = Event::create(['name' => 'Welcome', 'visibility' => 'everyone', 'timezone' => 'UTC']);
        $media = Media::factory()->for($owner)->approved()->create(['purpose' => MediaPurpose::Event, 'event_id' => $event->id]);
        $avatar = Media::factory()->for($owner)->profilePicture()->approved()->create();
        config(['instance.modules.profiles' => false, 'instance.modules.events' => true]);
        $this->actingAs($viewer)->getJson('/api/events/media/'.$media->ulid)->assertOk();
        foreach ([$media, $avatar] as $item) {
            $this->assertTrue(Gate::forUser($owner)->allows('view', $item));
            $this->assertTrue($item->isViewableBy($owner));
            $this->assertTrue(Media::query()->viewableBy($owner)->whereKey($item->id)->exists());
        }
    }
}
