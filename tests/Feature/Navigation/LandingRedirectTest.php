<?php

namespace Tests\Feature\Navigation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The nav consolidation lands signed-in users on their profile home (/me): the
 * marketing home, the retired /dashboard, and the old /feed all forward there,
 * while guests still see the marketing home.
 */
class LandingRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_the_marketing_home(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_signed_in_user_is_sent_from_home_to_profile(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->get('/')->assertRedirect(route('me'));
    }

    public function test_dashboard_forwards_to_profile(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('me'));
    }

    public function test_feed_forwards_to_profile(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->get('/feed')->assertRedirect(route('me'));
    }
}
