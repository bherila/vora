<?php

namespace Tests\Feature\Navigation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The marketing home remains public while signed-in entry points land on the
 * feed. The legacy /characters route still forwards to the profile.
 */
class LandingRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_the_marketing_home(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_signed_in_user_is_sent_from_home_to_feed(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->get('/')->assertRedirect(route('feed'));
    }

    public function test_dashboard_forwards_to_feed(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('feed'));
    }

    public function test_feed_forwards_to_profile(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->get('/feed')->assertRedirect(route('me'));
    }

    public function test_characters_still_forwards_to_profile(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->get('/characters')->assertRedirect(route('me'));
    }
}
