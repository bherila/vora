<?php

namespace Tests\Feature\Follow;

use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Post;
use App\Models\User;
use App\Support\FollowGraph;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PersonaFollowGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_boolean_and_correlated_rules_agree_for_account_linked_and_separate_identities(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $linked = Character::factory()->for($owner)->create(['is_linked' => true]);
        $separate = Character::factory()->for($owner)->create(['is_linked' => false]);

        $posts = collect([
            Post::factory()->for($owner)->approved()->create(['character_id' => null]),
            Post::factory()->for($owner)->approved()->create(['character_id' => $linked->id]),
            Post::factory()->for($owner)->approved()->create(['character_id' => $separate->id]),
        ]);

        $accountEdge = FollowRequest::query()->create([
            'requester_id' => $viewer->id,
            'recipient_id' => $owner->id,
            'recipient_character_id' => null,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        $this->assertRuleParity($viewer, $owner, $posts, [
            $posts[0]->id,
            $posts[1]->id,
        ]);

        $accountEdge->delete();
        FollowRequest::query()->create([
            'requester_id' => $viewer->id,
            'recipient_id' => $owner->id,
            'recipient_character_id' => $separate->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        $this->assertRuleParity($viewer, $owner, $posts, [$posts[2]->id]);
        $this->assertFalse(FollowGraph::follows($viewer->id, $owner->id));
    }

    public function test_nullable_scope_key_prevents_duplicate_account_and_persona_edges(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create();

        FollowRequest::query()->create([
            'requester_id' => $viewer->id,
            'recipient_id' => $owner->id,
            'recipient_character_id' => null,
        ]);

        try {
            FollowRequest::query()->create([
                'requester_id' => $viewer->id,
                'recipient_id' => $owner->id,
                'recipient_character_id' => null,
            ]);
            $this->fail('Duplicate account follow edges must be rejected.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        FollowRequest::query()->create([
            'requester_id' => $viewer->id,
            'recipient_id' => $owner->id,
            'recipient_character_id' => $character->id,
        ]);

        $this->expectException(QueryException::class);
        FollowRequest::query()->create([
            'requester_id' => $viewer->id,
            'recipient_id' => $owner->id,
            'recipient_character_id' => $character->id,
        ]);
    }

    public function test_deleting_a_persona_removes_its_edge_without_promoting_it_to_an_account_follow(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create(['is_linked' => false]);
        FollowRequest::query()->create([
            'requester_id' => $viewer->id,
            'recipient_id' => $owner->id,
            'recipient_character_id' => $character->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        $character->delete();

        $this->assertDatabaseMissing('follow_requests', [
            'requester_id' => $viewer->id,
            'recipient_id' => $owner->id,
        ]);
        $this->assertFalse(FollowGraph::follows($viewer->id, $owner->id));
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @param  list<int>  $expectedIds
     */
    private function assertRuleParity(User $viewer, User $owner, Collection $posts, array $expectedIds): void
    {
        $booleanIds = $posts
            ->filter(fn (Post $post): bool => FollowGraph::followsIdentity(
                $viewer->id,
                $owner->id,
                $post->character_id,
            ))
            ->pluck('id')
            ->all();

        $queryIds = Post::query()
            ->whereKey($posts->pluck('id'))
            ->whereExists(fn (QueryBuilder $query) => FollowGraph::constrainViewerFollowsOwner(
                $query,
                'posts.user_id',
                $viewer->id,
                'posts.character_id',
            ))
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing($expectedIds, $booleanIds);
        $this->assertEqualsCanonicalizing($expectedIds, $queryIds);
        $this->assertEqualsCanonicalizing($booleanIds, $queryIds);
    }
}
