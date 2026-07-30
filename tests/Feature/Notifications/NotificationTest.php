<?php

namespace Tests\Feature\Notifications;

use App\Enums\Audience;
use App\Jobs\NotifyFollowersOfPost;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Post;
use App\Models\User;
use App\Notifications\FollowedUserPosted;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function follow(User $follower, User $followee, ?Character $character = null): void
    {
        FollowRequest::query()->create([
            'requester_id' => $follower->id,
            'recipient_id' => $followee->id,
            'recipient_character_id' => $character?->id,
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

    public function test_separate_persona_post_only_notifies_that_personas_followers(): void
    {
        User::factory()->create();
        $author = User::factory()->approved()->create();
        $ownerFollower = User::factory()->approved()->create();
        $personaFollower = User::factory()->approved()->create();
        $otherPersonaFollower = User::factory()->approved()->create();
        $persona = Character::factory()->for($author)->create([
            'display_name' => 'Distinct Persona',
            'is_linked' => false,
        ]);
        $otherPersona = Character::factory()->for($author)->create(['is_linked' => false]);
        $this->follow($ownerFollower, $author);
        $this->follow($personaFollower, $author, $persona);
        $this->follow($otherPersonaFollower, $author, $otherPersona);
        $post = Post::factory()->for($author)->approved()->audience(Audience::Followers)->create([
            'character_id' => $persona->id,
        ]);

        (new NotifyFollowersOfPost($post))->handle();

        $this->assertSame(0, $ownerFollower->notifications()->count());
        $this->assertSame(0, $otherPersonaFollower->notifications()->count());
        $this->assertSame(1, $personaFollower->notifications()->count());
        $data = $personaFollower->notifications()->first()->data;
        $this->assertSame('Distinct Persona', $data['actor_name']);
        $this->assertSame($persona->id, $data['actor_character_id']);
        $this->assertSame('/p/'.$post->ulid, $data['url']);
        $this->assertArrayNotHasKey('actor_id', $data);

        $responseData = $this->actingAs($personaFollower)
            ->getJson('/api/notifications')
            ->assertOk()
            ->json('data.0.data');
        $this->assertSame('Distinct Persona', $responseData['actor_name']);
        $this->assertSame('/p/'.$post->ulid, $responseData['url']);
        $this->assertArrayNotHasKey('actor_id', $responseData);

        $push = (new FollowedUserPosted($post))->toWebPush(
            $personaFollower,
            new FollowedUserPosted($post),
        )->toArray();
        $this->assertSame('Distinct Persona posted something new.', $push['body']);
        $this->assertSame([
            'url' => '/p/'.$post->ulid,
            'type' => 'new_post',
        ], $push['data']);
    }

    public function test_account_and_linked_persona_notifications_retain_public_owner_attribution(): void
    {
        $author = User::factory()->approved()->create(['display_name' => 'Public Owner']);
        $linkedPersona = Character::factory()->for($author)->create([
            'display_name' => 'Linked Persona',
            'is_linked' => true,
        ]);

        $accountPost = Post::factory()->for($author)->approved()->create();
        $linkedPost = Post::factory()->for($author)->approved()->create([
            'character_id' => $linkedPersona->id,
        ]);

        $this->assertSame($author->id, (new FollowedUserPosted($accountPost))->toArray($author)['actor_id']);
        $linkedData = (new FollowedUserPosted($linkedPost))->toArray($author);
        $this->assertSame($author->id, $linkedData['actor_id']);
        $this->assertSame($linkedPersona->id, $linkedData['actor_character_id']);
        $this->assertSame('Linked Persona', $linkedData['actor_name']);
    }

    public function test_overlapping_account_and_linked_persona_edges_send_one_notification(): void
    {
        User::factory()->create();
        $author = User::factory()->approved()->create();
        $follower = User::factory()->approved()->create();
        $persona = Character::factory()->for($author)->create(['is_linked' => true]);
        $this->follow($follower, $author);
        $this->follow($follower, $author, $persona);
        $post = Post::factory()->for($author)->approved()->audience(Audience::Followers)->create([
            'character_id' => $persona->id,
        ]);

        (new NotifyFollowersOfPost($post))->handle();

        $this->assertSame(1, $follower->notifications()->count());
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

    public function test_purging_a_persona_owner_removes_notifications_referencing_the_persona(): void
    {
        User::factory()->create();
        $author = User::factory()->approved()->create();
        $follower = User::factory()->approved()->create();
        $persona = Character::factory()->for($author)->create(['is_linked' => false]);
        $this->follow($follower, $author, $persona);
        $post = Post::factory()->for($author)->approved()->create(['character_id' => $persona->id]);
        (new NotifyFollowersOfPost($post))->handle();
        $this->assertSame(1, $follower->notifications()->count());

        app(UserAccountService::class)->purge($author);

        $this->assertSame(0, $follower->notifications()->count());
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
