<?php

namespace Tests\Feature\Posts;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostFeedTest extends TestCase
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

    /**
     * @return list<string> the ulids in the viewer's feed
     */
    private function feedUlids(User $viewer): array
    {
        return collect($this->actingAs($viewer)->getJson('/api/feed')->assertOk()->json('data'))
            ->pluck('ulid')->all();
    }

    public function test_feed_contains_own_and_followed_posts_only(): void
    {
        // Create the unfollowed author first so the viewer is never user id 1
        // (which is always treated as an admin and would bypass the audience gate).
        $unfollowed = User::factory()->approved()->create();
        $followed = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $this->follow($viewer, $followed);

        $own = Post::factory()->for($viewer)->approved()->create();
        $followedPost = Post::factory()->for($followed)->approved()->create();
        $unfollowedPost = Post::factory()->for($unfollowed)->approved()->create();

        $ulids = $this->feedUlids($viewer);

        $this->assertEqualsCanonicalizing([$own->ulid, $followedPost->ulid], $ulids);
        $this->assertNotContains($unfollowedPost->ulid, $ulids);
    }

    public function test_feed_applies_the_audience_tier_of_followed_posts(): void
    {
        $followed = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $this->follow($viewer, $followed); // one-way: viewer follows, not mutual

        $followersPost = Post::factory()->for($followed)->approved()->audience(Audience::Followers)->create();
        $mutualsPost = Post::factory()->for($followed)->approved()->audience(Audience::Mutuals)->create();

        $ulids = $this->feedUlids($viewer);

        $this->assertContains($followersPost->ulid, $ulids, 'a follower sees a followers-only post');
        $this->assertNotContains($mutualsPost->ulid, $ulids, 'a one-way follow is not a mutual');
    }

    public function test_persona_only_follow_includes_only_that_personas_posts(): void
    {
        User::factory()->create();
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $followedPersona = Character::factory()->for($author)->create(['is_linked' => false]);
        $otherPersona = Character::factory()->for($author)->create(['is_linked' => false]);
        $this->follow($viewer, $author, $followedPersona);

        $ownerPost = Post::factory()->for($author)->approved()->audience(Audience::Followers)->create();
        $followedPersonaPost = Post::factory()->for($author)->approved()->audience(Audience::Followers)->create([
            'character_id' => $followedPersona->id,
        ]);
        $otherPersonaPost = Post::factory()->for($author)->approved()->audience(Audience::Followers)->create([
            'character_id' => $otherPersona->id,
        ]);

        $ulids = $this->feedUlids($viewer);

        $this->assertSame([$followedPersonaPost->ulid], $ulids);
        $this->assertNotContains($ownerPost->ulid, $ulids);
        $this->assertNotContains($otherPersonaPost->ulid, $ulids);
    }

    public function test_feed_hides_unapproved_posts_from_others_but_not_your_own(): void
    {
        $followed = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $this->follow($viewer, $followed);

        $othersRejected = Post::factory()->for($followed)->rejected()->create();
        $ownRejected = Post::factory()->for($viewer)->rejected()->create();

        $ulids = $this->feedUlids($viewer);

        $this->assertNotContains($othersRejected->ulid, $ulids, "another user's unreviewed post is hidden");
        $this->assertContains($ownRejected->ulid, $ulids, 'the author always sees their own post');
    }

    public function test_feed_excludes_posts_from_an_inactive_followed_author(): void
    {
        $followed = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $this->follow($viewer, $followed);
        $post = Post::factory()->for($followed)->approved()->create();

        $this->assertContains($post->ulid, $this->feedUlids($viewer));

        // The author deactivates after the viewer followed them (deactivated_at
        // is not mass-assignable, so set it directly).
        $followed->forceFill(['deactivated_at' => now()])->save();

        $this->assertNotContains($post->ulid, $this->feedUlids($viewer), 'an inactive author drops out of the feed');
    }

    public function test_feed_is_newest_first(): void
    {
        $spacer = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();

        $first = Post::factory()->for($viewer)->approved()->create();
        $second = Post::factory()->for($viewer)->approved()->create();
        $third = Post::factory()->for($viewer)->approved()->create();

        // Same created_at in tests; the id desc tiebreaker keeps it deterministic.
        $this->assertSame([$third->ulid, $second->ulid, $first->ulid], $this->feedUlids($viewer));
    }

    public function test_feed_paginates_with_a_cursor(): void
    {
        $spacer = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $pageSize = (int) config('media.page_size', 24);
        Post::factory()->for($viewer)->approved()->count($pageSize + 3)->create();

        $first = $this->actingAs($viewer)->getJson('/api/feed')->assertOk();
        $this->assertCount($pageSize, $first->json('data'));
        $cursor = $first->json('next_cursor');
        $this->assertNotNull($cursor);

        $second = $this->actingAs($viewer)->getJson('/api/feed?cursor='.$cursor)->assertOk();
        $this->assertCount(3, $second->json('data'));
        $this->assertNull($second->json('next_cursor'));
    }
}
