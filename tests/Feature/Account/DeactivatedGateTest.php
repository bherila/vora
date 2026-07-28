<?php

namespace Tests\Feature\Account;

use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Media;
use App\Models\Story;
use App\Models\StoryAuthor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that EnsureNotDeactivated blocks every mutating endpoint for a
 * self-deactivated user. The middleware runs after route-model binding, so
 * each check uses a real record ID to ensure the binding resolves before the
 * 403 is returned.
 */
class DeactivatedGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_user_is_blocked_from_all_write_endpoints(): void
    {
        // Consume id 1 so neither test account is the primary admin (id 1 is
        // always treated as admin, which could mask a gate regression that only
        // affects ordinary users).
        User::factory()->create();

        $user = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();

        // Create the user's own content while still active.
        $story = Story::factory()->for($user)->create();
        $character = Character::query()->create(['user_id' => $user->id, 'display_name' => 'X']);
        $media = Media::factory()->for($user)->create();

        // An incoming follow request that the user could accept/decline.
        $followRequest = FollowRequest::query()->create([
            'requester_id' => $other->id,
            'recipient_id' => $user->id,
            'status' => 'pending',
        ]);

        // A co-author invite where the user is the invitee.
        $invite = StoryAuthor::query()->create([
            'story_id' => Story::factory()->for($other)->create()->id,
            'user_id' => $user->id,
            'invited_by_user_id' => $other->id,
            'role' => StoryAuthor::ROLE_CO_AUTHOR,
            'status' => StoryAuthor::STATUS_PENDING,
        ]);

        $user->forceFill(['deactivated_at' => now()])->save();

        $endpoints = [
            // Profile (auth-only group — no approved gate, so middleware is the only guard)
            ['PATCH', '/api/account'],
            ['POST', '/api/account/profile-picture'],
            ['POST', "/api/account/profile-picture/{$media->id}/complete"],
            ['DELETE', '/api/account/profile-picture'],
            ['POST', '/api/account/deactivate'],
            ['POST', '/api/account/delete'],

            // Characters
            ['POST', '/api/characters'],
            ['PATCH', "/api/characters/{$character->id}"],
            ['DELETE', "/api/characters/{$character->id}"],
            ['POST', "/api/characters/{$character->id}/follow"],
            ['POST', "/api/characters/{$character->id}/profile-picture"],
            ['POST', "/api/characters/{$character->id}/profile-picture/{$media->id}/complete"],
            ['DELETE', "/api/characters/{$character->id}/profile-picture"],

            // Media
            ['POST', '/api/media'],
            ['POST', "/api/media/{$media->id}/complete"],
            ['DELETE', "/api/media/{$media->id}"],

            // Stories
            ['POST', '/api/stories'],
            ['PATCH', "/api/stories/{$story->id}"],
            ['DELETE', "/api/stories/{$story->id}"],
            ['PUT', "/api/stories/{$story->id}/graph"],
            ['POST', "/api/stories/{$story->id}/authors"],
            ['DELETE', "/api/stories/{$story->id}/authors/{$other->id}"],

            // Authorship invites
            ['POST', "/api/authorship-invites/{$invite->id}/accept"],
            ['POST', "/api/authorship-invites/{$invite->id}/decline"],

            // Follow requests
            ['POST', "/api/users/{$other->id}/follow-requests"],
            ['POST', "/api/users/follow-requests/{$followRequest->id}/accept"],
            ['POST', "/api/users/follow-requests/{$followRequest->id}/decline"],

            // Interests
            ['POST', '/api/interests/ratings'],
            ['POST', '/api/interests/inherit'],
            ['POST', '/api/interests/request'],
        ];

        foreach ($endpoints as [$method, $path]) {
            $status = $this->actingAs($user)->json($method, $path, [])->status();
            $this->assertSame(403, $status, "Expected 403 for deactivated user on $method $path, got $status");
        }
    }

    public function test_deactivated_user_settings_page_redirects_to_gate(): void
    {
        $user = User::factory()->approved()->create(['deactivated_at' => now()]);

        $this->actingAs($user)->get('/user/settings')
            ->assertRedirect(route('account.deactivated'));
    }

    public function test_deactivated_user_can_still_reach_the_gate_page_and_reactivate(): void
    {
        $user = User::factory()->approved()->create(['deactivated_at' => now()]);

        $this->actingAs($user)->get('/account/deactivated')->assertOk();

        $this->actingAs($user)->post('/account/reactivate')->assertRedirect('/');
        $this->assertNull($user->refresh()->deactivated_at);
    }
}
