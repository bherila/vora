<?php

namespace Tests\Feature\Notifications;

use App\Enums\Audience;
use App\Models\FollowRequest;
use App\Models\Post;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
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

    public function test_accepting_a_follow_notifies_the_requester(): void
    {
        $requester = User::factory()->approved()->create();
        $recipient = User::factory()->approved()->create();
        $followRequest = FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'status' => 'pending',
        ]);

        $this->actingAs($recipient)->postJson("/api/users/follow-requests/{$followRequest->id}/accept")->assertOk();

        $this->assertSame(1, $requester->notifications()->count());
        $this->assertSame('follow_accepted', $requester->notifications()->first()->data['type']);
    }

    public function test_a_new_post_only_notifies_followers_who_can_view_it(): void
    {
        // Spacer so the follower is not user id 1 (admin bypasses the audience).
        User::factory()->create();
        $author = User::factory()->approved()->create();
        $follower = User::factory()->approved()->create();
        $this->follow($follower, $author); // one-way: not a mutual

        // A followers-only post reaches the follower.
        $this->actingAs($author)->postJson('/api/posts', ['body' => 'hi', 'audience' => Audience::Followers->value])->assertCreated();
        // A mutuals-only post does not (the follow is one-way).
        $this->actingAs($author)->postJson('/api/posts', ['body' => 'secret', 'audience' => Audience::Mutuals->value])->assertCreated();

        $this->assertSame(1, $follower->notifications()->count(), 'only the viewable post notifies');
        $this->assertSame('new_post', $follower->notifications()->first()->data['type']);
    }

    public function test_a_reaction_notifies_the_author_but_not_for_self(): void
    {
        $author = User::factory()->approved()->create();
        $reactor = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();

        $this->actingAs($reactor)->postJson("/api/posts/{$post->id}/reactions")->assertOk();
        $this->assertSame(1, $author->notifications()->count());
        $this->assertSame('post_reaction', $author->notifications()->first()->data['type']);

        // The author reacting to their own post does not notify themselves.
        $this->actingAs($author)->postJson("/api/posts/{$post->id}/reactions")->assertOk();
        $this->assertSame(1, $author->notifications()->count());
    }

    public function test_a_comment_notifies_the_author_but_not_for_self(): void
    {
        $author = User::factory()->approved()->create();
        $commenter = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();

        $this->actingAs($commenter)->postJson("/api/posts/{$post->id}/comments", ['body' => 'nice'])->assertCreated();
        $this->assertSame(1, $author->notifications()->count());

        $this->actingAs($author)->postJson("/api/posts/{$post->id}/comments", ['body' => 'thanks'])->assertCreated();
        $this->assertSame(1, $author->notifications()->count(), 'no self-notification');
    }

    public function test_the_post_page_route_resolves_so_notification_links_do_not_404(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();

        $this->actingAs($viewer)->get("/p/{$post->ulid}")->assertOk();
    }

    public function test_purging_an_actor_removes_notifications_referencing_them(): void
    {
        $author = User::factory()->approved()->create();
        $reactor = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();
        $this->actingAs($reactor)->postJson("/api/posts/{$post->id}/reactions");
        $this->assertSame(1, $author->notifications()->count());

        app(UserAccountService::class)->purge($reactor);

        $this->assertSame(0, $author->notifications()->count(), 'erasing the actor removes notifications about them');
    }

    public function test_unread_count_and_marking_read(): void
    {
        $author = User::factory()->approved()->create();
        $reactorA = User::factory()->approved()->create();
        $reactorB = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();
        $this->actingAs($reactorA)->postJson("/api/posts/{$post->id}/reactions");
        $this->actingAs($reactorB)->postJson("/api/posts/{$post->id}/reactions");

        $this->actingAs($author)->getJson('/api/notifications/unread-count')->assertJsonPath('data.count', 2);
        $this->actingAs($author)->getJson('/api/notifications')->assertOk()->assertJsonCount(2, 'data');

        $firstId = $author->notifications()->first()->id;
        $this->actingAs($author)->postJson("/api/notifications/{$firstId}/read")->assertOk();
        $this->actingAs($author)->getJson('/api/notifications/unread-count')->assertJsonPath('data.count', 1);

        $this->actingAs($author)->postJson('/api/notifications/read-all')->assertOk();
        $this->actingAs($author)->getJson('/api/notifications/unread-count')->assertJsonPath('data.count', 0);
    }
}
