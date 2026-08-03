<?php

namespace Tests\Feature\Posts;

use App\Enums\Audience;
use App\Models\Block;
use App\Models\FollowRequest;
use App\Models\Interest;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_authored_contributions_remain_neutrally_available_after_parent_access_is_lost(): void
    {
        User::factory()->create(); // keep the accounts under test non-admin
        $alice = User::factory()->approved()->create(['display_name' => 'Alice Private']);
        $bob = User::factory()->approved()->create();
        $charlie = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Secret Hiking']);
        $follow = FollowRequest::query()->create([
            'requester_id' => $bob->id,
            'recipient_id' => $alice->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
        $post = Post::factory()->for($alice)->approved()->audience(Audience::Followers)->create([
            'body' => 'Private parent body',
            'context_interest_id' => $interest->id,
        ]);

        $root = $this->actingAs($bob)->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'Bob root',
        ])->assertCreated();
        $rootComment = PostComment::query()->where('ulid', $root->json('data.ulid'))->firstOrFail();
        $reply = $this->actingAs($bob)->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'Bob reply',
            'parent_id' => $rootComment->id,
        ])->assertCreated();
        $replyComment = PostComment::query()->where('ulid', $reply->json('data.ulid'))->firstOrFail();
        $otherComment = PostComment::factory()->for($post)->for($charlie)->create();

        $follow->delete();
        $this->actingAs($bob)->getJson("/api/posts/{$post->id}/comments")->assertForbidden();

        $commentResponse = $this->actingAs($bob)->getJson('/api/me/activity?type=comments')->assertOk();
        $commentResponse->assertJsonCount(1, 'data');
        $item = $commentResponse->json('data.0');
        $this->assertSame(
            ['ulid', 'type', 'body', 'status', 'created_at', 'parent', 'parent_unavailable'],
            array_keys($item),
        );
        $this->assertSame('Bob root', $item['body']);
        $this->assertNull($item['parent']);
        $this->assertTrue($item['parent_unavailable']);
        $this->assertStringNotContainsString('Private parent body', $commentResponse->getContent());
        $this->assertStringNotContainsString('Secret Hiking', $commentResponse->getContent());
        $this->assertStringNotContainsString('Alice Private', $commentResponse->getContent());

        $this->actingAs($bob)->getJson('/api/me/activity?type=replies')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Bob reply')
            ->assertJsonPath('data.0.parent', null)
            ->assertJsonPath('data.0.parent_unavailable', true);

        $foreign = $this->actingAs($bob)
            ->deleteJson('/api/me/activity/comments/'.$otherComment->ulid)
            ->assertNotFound();
        $missing = $this->actingAs($bob)
            ->deleteJson('/api/me/activity/comments/01ARZ3NDEKTSV4RRFFQ69G5FAV')
            ->assertNotFound();
        $this->assertSame($missing->getContent(), $foreign->getContent());

        $this->actingAs($bob)->deleteJson('/api/me/activity/comments/'.$rootComment->ulid)->assertOk();
        $this->assertSoftDeleted('post_comments', ['id' => $rootComment->id]);
        $this->actingAs($bob)->getJson('/api/me/activity?type=comments')->assertJsonCount(0, 'data');
        $this->actingAs($bob)->getJson('/api/me/activity?type=replies')->assertJsonCount(1, 'data');

        $this->actingAs($bob)->deleteJson('/api/me/activity/comments/'.$replyComment->ulid)->assertOk();
        $this->actingAs($bob)->getJson('/api/me/activity?type=replies')->assertJsonCount(0, 'data');
    }

    public function test_author_delete_owner_removal_and_admin_rejection_stay_distinct(): void
    {
        User::factory()->create(); // keep the accounts under test non-admin
        $owner = User::factory()->approved()->create();
        $author = User::factory()->approved()->create();
        $replier = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->for($owner)->approved()->create();

        $deletedRoot = PostComment::factory()->for($post)->for($author)->create(['body' => 'Deleted root']);
        $survivingReply = PostComment::factory()->for($post)->for($replier)->create([
            'parent_id' => $deletedRoot->id,
            'body' => 'Surviving reply',
        ]);
        $deletedLeaf = PostComment::factory()->for($post)->for($author)->create(['body' => 'Deleted leaf']);
        $replyParent = PostComment::factory()->for($post)->for($replier)->create(['body' => 'Reply parent']);
        $deletedReply = PostComment::factory()->for($post)->for($author)->create([
            'parent_id' => $replyParent->id,
            'body' => 'Deleted reply leaf',
        ]);

        $this->actingAs($author)->deleteJson("/api/posts/{$post->id}/comments/{$deletedRoot->id}")->assertOk();
        $this->actingAs($author)->deleteJson("/api/posts/{$post->id}/comments/{$deletedLeaf->id}")->assertOk();
        $this->actingAs($author)->deleteJson("/api/posts/{$post->id}/comments/{$deletedReply->id}")->assertOk();

        $thread = collect($this->actingAs($viewer)->getJson("/api/posts/{$post->id}/comments")->assertOk()->json('data'));
        $this->assertTrue($thread->contains(fn (array $item): bool => $item['ulid'] === $deletedRoot->ulid && $item['deleted'] === true));
        $this->assertTrue($thread->contains(fn (array $item): bool => $item['ulid'] === $survivingReply->ulid));
        $this->assertFalse($thread->contains(fn (array $item): bool => $item['ulid'] === $deletedLeaf->ulid));
        $this->assertFalse($thread->contains(fn (array $item): bool => $item['ulid'] === $deletedReply->ulid));

        $removedLeaf = PostComment::factory()->for($post)->for($author)->create(['body' => 'Owner removed leaf']);
        $removedReply = PostComment::factory()->for($post)->for($author)->create([
            'parent_id' => $replyParent->id,
            'body' => 'Owner removed reply',
        ]);
        $this->actingAs($owner)->deleteJson("/api/posts/{$post->id}/comments/{$removedLeaf->id}")->assertOk();
        $this->actingAs($owner)->deleteJson("/api/posts/{$post->id}/comments/{$removedReply->id}")->assertOk();

        $this->assertDatabaseHas('post_comments', [
            'id' => $removedLeaf->id,
            'removed_by_user_id' => $owner->id,
        ]);
        $thread = collect($this->actingAs($viewer)->getJson("/api/posts/{$post->id}/comments")->assertOk()->json('data'));
        $this->assertTrue($thread->contains(fn (array $item): bool => $item['ulid'] === $removedLeaf->ulid && $item['deleted'] === true));
        $this->assertTrue($thread->contains(fn (array $item): bool => $item['ulid'] === $removedReply->ulid && $item['deleted'] === true));
        $this->actingAs($author)->getJson('/api/me/activity?type=comments')
            ->assertJsonFragment(['ulid' => $removedLeaf->ulid, 'status' => 'removed_by_post_owner']);
        $this->actingAs($author)->getJson('/api/me/activity?type=replies')
            ->assertJsonFragment(['ulid' => $removedReply->ulid, 'status' => 'removed_by_post_owner']);

        $removedThenRejected = PostComment::factory()->for($post)->for($author)->create(['body' => 'Removed then rejected']);
        $itsReply = PostComment::factory()->for($post)->for($replier)->create([
            'parent_id' => $removedThenRejected->id,
            'body' => 'Must become orphaned',
        ]);
        $this->actingAs($owner)
            ->deleteJson("/api/posts/{$post->id}/comments/{$removedThenRejected->id}")
            ->assertOk();
        $this->actingAs($admin)->postJson("/api/admin/post-comments/{$removedThenRejected->id}/moderate", [
            'action' => 'reject',
        ])->assertOk();
        $thread = collect($this->actingAs($viewer)->getJson("/api/posts/{$post->id}/comments")->assertOk()->json('data'));
        $this->assertFalse($thread->contains(fn (array $item): bool => in_array($item['ulid'], [$removedThenRejected->ulid, $itsReply->ulid], true)));
        $this->actingAs($author)->getJson('/api/me/activity?type=comments')
            ->assertJsonFragment(['ulid' => $removedThenRejected->ulid, 'status' => 'rejected']);

        $rejectedRoot = PostComment::factory()->for($post)->for($author)->create(['body' => 'Rejected root']);
        $rejectedReply = PostComment::factory()->for($post)->for($replier)->create([
            'parent_id' => $rejectedRoot->id,
            'body' => 'Rejected orphan',
        ]);
        $this->actingAs($admin)
            ->deleteJson("/api/posts/{$post->id}/comments/{$rejectedRoot->id}")
            ->assertNotFound();
        $this->assertDatabaseHas('post_comments', ['id' => $rejectedRoot->id, 'deleted_at' => null]);

        $this->actingAs($admin)->postJson("/api/admin/post-comments/{$rejectedRoot->id}/moderate", [
            'action' => 'reject',
        ])->assertOk();
        $thread = collect($this->actingAs($viewer)->getJson("/api/posts/{$post->id}/comments")->assertOk()->json('data'));
        $this->assertFalse($thread->contains(fn (array $item): bool => in_array($item['ulid'], [$rejectedRoot->ulid, $rejectedReply->ulid], true)));
        $this->actingAs($author)->getJson('/api/me/activity?type=comments')
            ->assertJsonFragment(['ulid' => $rejectedRoot->ulid, 'status' => 'rejected']);
    }

    public function test_blocked_comment_tombstone_and_replies_match_an_empty_thread_payload(): void
    {
        User::factory()->create(); // keep the accounts under test non-admin
        $owner = User::factory()->approved()->create();
        $commenter = User::factory()->approved()->create();
        $replier = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($owner)->approved()->create();
        $emptyPost = Post::factory()->for($owner)->approved()->create();
        $root = PostComment::factory()->for($post)->for($commenter)->create();
        PostComment::factory()->for($post)->for($replier)->create(['parent_id' => $root->id]);

        $this->actingAs($commenter)
            ->deleteJson("/api/posts/{$post->id}/comments/{$root->id}")
            ->assertOk();
        $this->actingAs($viewer)->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.deleted', true);

        Block::query()->create([
            'blocker_id' => $viewer->id,
            'blocked_user_id' => $commenter->id,
        ]);

        $blocked = $this->actingAs($viewer)->getJson("/api/posts/{$post->id}/comments")->assertOk();
        $empty = $this->actingAs($viewer)->getJson("/api/posts/{$emptyPost->id}/comments")->assertOk();
        $this->assertSame($empty->getContent(), $blocked->getContent());
    }

    public function test_activity_page_is_linked_and_uses_its_registered_entrypoint(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->get('/me/activity')
            ->assertOk()
            ->assertSee('your-activity');
        $this->actingAs($user)->get('/feed')->assertSee('Your activity');
        $this->actingAs($user)->getJson('/api/me/activity?type=unknown')->assertUnprocessable();
    }

    public function test_activity_paginates_by_cursor_without_repeating_or_dropping_items(): void
    {
        $user = User::factory()->approved()->create();
        $size = (int) config('media.page_size', 24);
        Post::factory()->for($user)->approved()->count($size + 5)->create();

        $first = $this->actingAs($user)->getJson('/api/me/activity?type=posts')->assertOk();
        $first->assertJsonCount($size, 'data');
        $cursor = $first->json('next_cursor');
        $this->assertIsString($cursor);

        $second = $this->actingAs($user)
            ->getJson('/api/me/activity?type=posts&cursor='.urlencode($cursor))
            ->assertOk();
        $second->assertJsonCount(5, 'data');
        $this->assertNull($second->json('next_cursor'));

        $ulids = array_merge(
            array_column($first->json('data'), 'ulid'),
            array_column($second->json('data'), 'ulid'),
        );
        $this->assertCount($size + 5, array_unique($ulids), 'no item is repeated across pages');
    }

    /**
     * The lazy canonical post created for a user's own media is generated on
     * their behalf, not written by them, so it must not appear in a listing of
     * what they wrote.
     */
    public function test_system_generated_discussion_posts_are_not_listed_as_the_users_own(): void
    {
        $user = User::factory()->approved()->create();
        Post::factory()->for($user)->approved()->create(['body' => 'Something I wrote']);
        Post::factory()->for($user)->approved()->create([
            'body' => 'Discuss this media.',
            'is_feed_hidden' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/me/activity?type=posts')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Something I wrote');
    }
}
