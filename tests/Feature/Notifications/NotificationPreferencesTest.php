<?php

namespace Tests\Feature\Notifications;

use App\Jobs\NotifyFollowersOfPost;
use App\Models\FollowRequest;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
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

    public function test_new_post_fan_out_is_dispatched_to_the_queue(): void
    {
        Queue::fake();
        $author = User::factory()->approved()->create();

        $this->actingAs($author)->postJson('/api/posts', ['body' => 'hello'])->assertCreated();

        Queue::assertPushed(NotifyFollowersOfPost::class);
    }

    public function test_a_follower_who_opted_out_is_not_notified_of_new_posts(): void
    {
        $author = User::factory()->approved()->create();
        $follower = User::factory()->approved()->create();
        $follower->update(['notify_new_post' => false]);
        $this->follow($follower, $author);

        $this->actingAs($author)->postJson('/api/posts', ['body' => 'hi'])->assertCreated();

        $this->assertSame(0, $follower->notifications()->count());
    }

    public function test_new_post_fan_out_job_is_deleted_when_the_post_model_is_missing(): void
    {
        $author = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();

        $job = new NotifyFollowersOfPost($post);

        $this->assertTrue($job->deleteWhenMissingModels);
    }

    public function test_new_post_fan_out_skips_inactive_authors(): void
    {
        $author = User::factory()->approved()->create();
        $follower = User::factory()->approved()->create();
        $this->follow($follower, $author);
        $post = Post::factory()->for($author)->approved()->create();

        $author->forceFill(['deactivated_at' => now()])->save();

        (new NotifyFollowersOfPost($post->refresh()))->handle();

        $this->assertSame(0, $follower->notifications()->count());
    }

    public function test_new_post_fan_out_only_notifies_followers_accepted_before_the_post(): void
    {
        $author = User::factory()->approved()->create();
        $existingFollower = User::factory()->approved()->create();
        $futureFollower = User::factory()->approved()->create();
        $postCreatedAt = now()->subMinutes(10);
        $post = Post::factory()->for($author)->approved()->create([
            'created_at' => $postCreatedAt,
            'updated_at' => $postCreatedAt,
        ]);

        FollowRequest::query()->create([
            'requester_id' => $existingFollower->id,
            'recipient_id' => $author->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => $postCreatedAt->copy()->subMinute(),
        ]);
        FollowRequest::query()->create([
            'requester_id' => $futureFollower->id,
            'recipient_id' => $author->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => $postCreatedAt->copy()->addMinute(),
        ]);

        (new NotifyFollowersOfPost($post))->handle();

        $this->assertSame(1, $existingFollower->notifications()->count());
        $this->assertSame(0, $futureFollower->notifications()->count());
    }

    public function test_reaction_notification_respects_the_authors_preference(): void
    {
        $author = User::factory()->approved()->create(['notify_post_reaction' => false]);
        $reactor = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();

        $this->actingAs($reactor)->postJson("/api/posts/{$post->id}/reactions")->assertOk();

        $this->assertSame(0, $author->notifications()->count());
    }

    public function test_comment_notification_respects_the_authors_preference(): void
    {
        $author = User::factory()->approved()->create(['notify_post_comment' => false]);
        $commenter = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();

        $this->actingAs($commenter)->postJson("/api/posts/{$post->id}/comments", ['body' => 'hi'])->assertCreated();

        $this->assertSame(0, $author->notifications()->count());
    }

    public function test_accepted_follow_notification_respects_the_requester_preference(): void
    {
        $requester = User::factory()->approved()->create(['notify_follow_accepted' => false]);
        $recipient = User::factory()->approved()->create();
        $followRequest = FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'status' => 'pending',
        ]);

        $this->actingAs($recipient)->postJson("/api/users/follow-requests/{$followRequest->id}/accept")->assertOk();

        $this->assertSame(0, $requester->notifications()->count());
    }

    public function test_preferences_can_be_updated_via_the_account_endpoint(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->patchJson('/api/account', [
            'name' => $user->name,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'notify_new_post' => false,
            'notify_post_reaction' => false,
        ])->assertOk()
            ->assertJsonPath('data.notify_new_post', false)
            ->assertJsonPath('data.notify_post_comment', true);

        $this->assertFalse($user->fresh()->notify_new_post);
        $this->assertFalse($user->fresh()->notify_post_reaction);
    }
}
