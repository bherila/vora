<?php

namespace Tests\Feature\Acceptance;

use App\Enums\Audience;
use App\Models\FollowRequest;
use App\Models\Interest;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoAccountDiscussionTest extends TestCase
{
    use RefreshDatabase;

    public function test_contributors_keep_control_after_losing_access_to_a_discussion(): void
    {
        User::factory()->create(); // Keep both acceptance-test accounts non-admin.
        $alice = User::factory()->approved()->create(['display_name' => 'Alice Private']);
        $bob = User::factory()->approved()->create(['display_name' => 'Bob Contributor']);
        $interest = Interest::query()->create(['name' => 'Secret Hiking']);
        $follow = FollowRequest::query()->create([
            'requester_id' => $bob->id,
            'recipient_id' => $alice->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        $created = $this->actingAs($alice)->postJson('/api/posts', [
            'body' => 'Alice private trail update',
            'audience' => Audience::Followers->value,
            'context_interest_slug' => $interest->slug,
        ])->assertCreated()
            ->assertJsonPath('data.audience', Audience::Followers->value)
            ->assertJsonPath('data.context_interest.slug', $interest->slug);
        $post = Post::query()->where('ulid', $created->json('data.ulid'))->firstOrFail();

        $this->assertContains(
            $post->ulid,
            collect($this->actingAs($bob)->getJson('/api/feed')->assertOk()->json('data'))->pluck('ulid'),
        );
        $filteredFeed = $this->actingAs($bob)
            ->getJson('/api/feed?interest='.$interest->slug)
            ->assertOk();
        $this->assertSame([$post->ulid], collect($filteredFeed->json('data'))->pluck('ulid')->all());

        $emptyThread = $this->actingAs($bob)
            ->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $emptyEtag = $emptyThread->headers->get('ETag');
        $this->assertNotNull($emptyEtag);

        $bobRootResponse = $this->actingAs($bob)->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'Bob root contribution',
        ])->assertCreated();
        $bobRoot = PostComment::query()->where('ulid', $bobRootResponse->json('data.ulid'))->firstOrFail();

        $aliceReply = $this->actingAs($alice)->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'Alice reply that must survive',
            'parent_id' => $bobRoot->id,
        ])->assertCreated();

        $aliceRootResponse = $this->actingAs($alice)->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'Alice second root',
        ])->assertCreated();
        $aliceRoot = PostComment::query()->where('ulid', $aliceRootResponse->json('data.ulid'))->firstOrFail();

        $bobReplyResponse = $this->actingAs($bob)->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'Bob reply contribution',
            'parent_id' => $aliceRoot->id,
        ])->assertCreated();
        $bobReply = PostComment::query()->where('ulid', $bobReplyResponse->json('data.ulid'))->firstOrFail();

        $changedThread = $this->actingAs($bob)
            ->withHeader('If-None-Match', $emptyEtag)
            ->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonCount(4, 'data');
        $currentEtag = $changedThread->headers->get('ETag');
        $this->assertNotSame($emptyEtag, $currentEtag);
        $this->actingAs($bob)
            ->withHeader('If-None-Match', $currentEtag)
            ->getJson("/api/posts/{$post->id}/comments")
            ->assertStatus(304)
            ->assertContent('');

        $follow->delete();

        $this->assertNotContains(
            $post->ulid,
            collect($this->actingAs($bob)->getJson('/api/feed')->assertOk()->json('data'))->pluck('ulid'),
        );
        $this->actingAs($bob)->getJson("/api/posts/by-ulid/{$post->ulid}")->assertNotFound();
        $this->actingAs($bob)
            ->withHeader('If-None-Match', $currentEtag)
            ->getJson("/api/posts/{$post->id}/comments")
            ->assertForbidden();

        $comments = $this->actingAs($bob)->getJson('/api/me/activity?type=comments')->assertOk();
        $comments->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ulid', $bobRoot->ulid)
            ->assertJsonPath('data.0.body', 'Bob root contribution')
            ->assertJsonPath('data.0.parent', null)
            ->assertJsonPath('data.0.parent_unavailable', true);
        $replies = $this->actingAs($bob)->getJson('/api/me/activity?type=replies')->assertOk();
        $replies->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ulid', $bobReply->ulid)
            ->assertJsonPath('data.0.body', 'Bob reply contribution')
            ->assertJsonPath('data.0.parent', null)
            ->assertJsonPath('data.0.parent_unavailable', true);

        foreach ([$comments, $replies] as $activity) {
            $this->assertStringNotContainsString('Alice Private', $activity->getContent());
            $this->assertStringNotContainsString('Alice private trail update', $activity->getContent());
            $this->assertStringNotContainsString('Secret Hiking', $activity->getContent());
        }

        $this->actingAs($bob)
            ->deleteJson('/api/me/activity/comments/'.$bobRoot->ulid)
            ->assertOk();
        $this->actingAs($bob)
            ->deleteJson('/api/me/activity/comments/'.$bobReply->ulid)
            ->assertOk();
        $this->assertSoftDeleted('post_comments', ['id' => $bobRoot->id]);
        $this->assertSoftDeleted('post_comments', ['id' => $bobReply->id]);

        $thread = collect($this->actingAs($alice)
            ->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->json('data'));
        $rootTombstone = $thread->firstWhere('ulid', $bobRoot->ulid);
        $this->assertIsArray($rootTombstone);
        $this->assertTrue($rootTombstone['deleted']);
        $this->assertNull($rootTombstone['body']);
        $this->assertNull($rootTombstone['author']);
        $this->assertTrue($thread->contains(fn (array $item): bool => $item['ulid'] === $aliceReply->json('data.ulid')));
        $this->assertFalse($thread->contains(fn (array $item): bool => $item['ulid'] === $bobReply->ulid));

        $this->actingAs($bob)->getJson('/api/me/activity?type=comments')->assertJsonCount(0, 'data');
        $this->actingAs($bob)->getJson('/api/me/activity?type=replies')->assertJsonCount(0, 'data');
    }
}
