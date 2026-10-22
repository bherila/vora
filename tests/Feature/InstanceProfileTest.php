<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\InstanceFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            $method = $path === '/api/favorites' ? 'postJson' : 'getJson';
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
}
