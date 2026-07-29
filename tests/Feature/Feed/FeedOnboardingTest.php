<?php

namespace Tests\Feature\Feed;

use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Interest;
use App\Models\InterestRating;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_sees_all_onboarding_steps_incomplete(): void
    {
        // Not user id 1 (treated as admin elsewhere) — create a throwaway first.
        User::factory()->approved()->create();
        $user = User::factory()->approved()->create();

        $onboarding = $this->onboarding($user);

        $this->assertSame([
            'display_name' => $user->display_name,
            'has_personas' => false,
            'steps' => [
                'has_avatar' => false,
                'has_interests' => false,
                'is_following' => false,
                'has_posted' => false,
            ],
        ], $onboarding);
    }

    public function test_onboarding_disappears_once_every_step_is_complete(): void
    {
        // Rendering the feed signs the current user's nav avatar; stub the
        // storage layer so the thumbnail URL resolves without a real bucket.
        $this->mock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/avatar.jpg');
        });

        User::factory()->approved()->create();
        $user = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();

        // Avatar.
        $avatar = Media::factory()->for($user)->profilePicture()->create();
        $user->forceFill(['profile_picture_media_id' => $avatar->id])->save();

        // Interest.
        $interest = Interest::query()->create(['name' => 'Hiking']);
        InterestRating::query()->create([
            'user_id' => $user->id,
            'character_id' => null,
            'interest_id' => $interest->id,
            'level' => 3,
        ]);

        // Following someone (accepted).
        FollowRequest::query()->create([
            'requester_id' => $user->id,
            'recipient_id' => $other->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        // First post.
        Post::factory()->for($user)->approved()->create();

        $this->assertNull($this->onboarding($user));
    }

    public function test_persona_only_follow_does_not_complete_human_following_step(): void
    {
        User::factory()->approved()->create();
        $user = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $persona = Character::factory()->for($other)->create(['is_linked' => false]);
        FollowRequest::query()->create([
            'requester_id' => $user->id,
            'recipient_id' => $other->id,
            'recipient_character_id' => $persona->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        $this->assertFalse($this->onboarding($user)['steps']['is_following']);
    }

    public function test_persona_is_exposed_as_optional_context_without_changing_required_progress(): void
    {
        User::factory()->approved()->create();
        $user = User::factory()->approved()->create();
        Character::factory()->for($user)->create();

        $onboarding = $this->onboarding($user);

        $this->assertTrue($onboarding['has_personas']);
        $this->assertCount(4, $onboarding['steps']);
    }

    public function test_dismissal_is_persisted_to_the_account_and_hides_onboarding_on_a_later_request(): void
    {
        User::factory()->approved()->create();
        $user = User::factory()->approved()->create();

        $this->actingAs($user)
            ->postJson('/api/onboarding/dismiss')
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertNotNull($user->fresh()->onboarding_dismissed_at);

        // A fresh HTTP request reads account state rather than device-local
        // storage, so another browser receives the same dismissed state.
        $this->assertNull($this->onboarding($user->fresh()));
    }

    /**
     * Parse the onboarding slice hydrated into the standalone feed page.
     *
     * @return array<string, mixed>|null
     */
    private function onboarding(User $user): ?array
    {
        $html = $this->actingAs($user)->get('/feed')->assertOk()->getContent();
        preg_match('/<script id="initial-data"[^>]*>\s*(.*?)\s*<\/script>/s', (string) $html, $matches);
        $this->assertArrayHasKey(1, $matches, 'initial-data script not found');

        /** @var array<string, mixed> $payload */
        $payload = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed>|null $onboarding */
        $onboarding = $payload['feedOnboarding'] ?? null;

        return $onboarding;
    }
}
