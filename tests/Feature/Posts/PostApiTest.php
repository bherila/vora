<?php

namespace Tests\Feature\Posts;

use App\Enums\Audience;
use App\Models\FollowRequest;
use App\Models\Post;
use App\Models\PrivacyAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostApiTest extends TestCase
{
    use RefreshDatabase;

    private function follow(User $follower, User $followee): void
    {
        FollowRequest::query()->create([
            'requester_id' => $follower->id,
            'recipient_id' => $followee->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
    }

    public function test_user_can_create_a_post(): void
    {
        $user = User::factory()->approved()->create();

        $response = $this->actingAs($user)->postJson('/api/posts', [
            'body' => 'Hello followers',
            'audience' => Audience::Everyone->value,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.body', 'Hello followers')
            ->assertJsonPath('data.audience', 'everyone');

        $post = Post::query()->firstOrFail();
        $this->assertTrue($post->isPendingReview(), 'new posts enter review');
        $this->assertTrue(
            PrivacyAuditLog::query()->where('privacyable_id', $post->id)->where('action', 'created')->exists(),
        );
    }

    public function test_post_requires_a_body(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->postJson('/api/posts', ['body' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('body');
    }

    public function test_non_follower_cannot_view_a_followers_only_post(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($owner)->approved()->audience(Audience::Followers)->create();

        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")->assertForbidden();

        $this->follow($viewer, $owner);
        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")->assertOk();
    }

    public function test_pending_post_is_hidden_from_non_owner(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($owner)->create(['audience' => Audience::Everyone]); // pending

        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")->assertForbidden();
        $this->actingAs($owner)->getJson("/api/posts/by-ulid/{$post->ulid}")->assertOk();
    }

    public function test_only_the_owner_can_delete_a_post(): void
    {
        $owner = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $post = Post::factory()->for($owner)->create();

        $this->actingAs($other)->deleteJson("/api/posts/{$post->id}")->assertForbidden();
        $this->actingAs($owner)->deleteJson("/api/posts/{$post->id}")->assertOk();
        $this->assertSame(0, Post::query()->count());
    }
}
