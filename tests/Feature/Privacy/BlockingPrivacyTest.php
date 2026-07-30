<?php

namespace Tests\Feature\Privacy;

use App\Enums\Audience;
use App\Models\Block;
use App\Models\BlockAuditLog;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\FollowRequestAuditLog;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\StoryAuthor;
use App\Models\User;
use App\Support\BlockGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockingPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocking_severs_only_the_follow_edges_each_side_may_observe_safely_and_audits_them(): void
    {
        User::factory()->create();
        $alice = User::factory()->approved()->create();
        $ben = User::factory()->approved()->create();
        $alicePersona = Character::factory()->for($alice)->create();
        $linked = Character::factory()->for($ben)->create(['is_linked' => true]);
        $separate = Character::factory()->for($ben)->create(['is_linked' => false]);

        $benToAlice = $this->follow($ben, $alice);
        $benToAlicePersona = $this->follow($ben, $alice, $alicePersona);
        $aliceToBen = $this->follow($alice, $ben);
        $aliceToLinked = $this->follow($alice, $ben, $linked);
        $aliceToSeparate = $this->follow($alice, $ben, $separate);

        $this->actingAs($alice)
            ->postJson("/api/users/{$ben->id}/block")
            ->assertCreated()
            ->assertJsonPath('data.blocked', true);

        $this->assertDatabaseMissing('follow_requests', ['id' => $benToAlice->id]);
        $this->assertDatabaseMissing('follow_requests', ['id' => $benToAlicePersona->id]);
        $this->assertDatabaseMissing('follow_requests', ['id' => $aliceToBen->id]);
        $this->assertDatabaseHas('follow_requests', ['id' => $aliceToLinked->id]);
        $this->assertDatabaseHas('follow_requests', ['id' => $aliceToSeparate->id]);
        $this->assertSame(3, FollowRequestAuditLog::query()->where('action', 'removed_by_block')->count());
        $this->assertDatabaseHas('block_audit_logs', [
            'actor_id' => $alice->id,
            'blocker_id' => $alice->id,
            'blocked_user_id' => $ben->id,
            'blocked_character_id' => null,
            'action' => BlockAuditLog::ACTION_BLOCKED,
        ]);
    }

    public function test_persona_block_removes_only_that_outbound_identity_but_denial_still_removes_every_reverse_edge(): void
    {
        User::factory()->create();
        $alice = User::factory()->approved()->create();
        $ben = User::factory()->approved()->create();
        $separate = Character::factory()->for($ben)->create(['is_linked' => false]);
        $other = Character::factory()->for($ben)->create(['is_linked' => false]);

        $aliceToBen = $this->follow($alice, $ben);
        $aliceToSeparate = $this->follow($alice, $ben, $separate);
        $aliceToOther = $this->follow($alice, $ben, $other);
        $benToAlice = $this->follow($ben, $alice);

        $this->actingAs($alice)
            ->postJson("/api/characters/{$separate->id}/block")
            ->assertCreated();

        $this->assertDatabaseHas('follow_requests', ['id' => $aliceToBen->id]);
        $this->assertDatabaseMissing('follow_requests', ['id' => $aliceToSeparate->id]);
        $this->assertDatabaseHas('follow_requests', ['id' => $aliceToOther->id]);
        $this->assertDatabaseMissing('follow_requests', ['id' => $benToAlice->id]);
    }

    public function test_separate_persona_block_leaves_owner_payloads_byte_identical(): void
    {
        User::factory()->create();
        $alice = User::factory()->approved()->create();
        $ben = User::factory()->approved()->create();
        $separate = Character::factory()->for($ben)->create(['is_linked' => false]);
        Media::factory()->for($ben)->approved()->create();
        Post::factory()->for($ben)->approved()->create();

        $routes = [
            "/api/users/{$ben->id}",
            "/api/users/{$ben->id}/content-counts",
            "/api/users/{$ben->id}/media",
            "/api/users/{$ben->id}/posts",
        ];
        $before = $this->payloads($alice, $routes);

        $this->actingAs($alice)->postJson("/api/characters/{$separate->id}/block")->assertCreated();

        $this->assertSame($before, $this->payloads($alice, $routes));
    }

    public function test_account_block_leaves_separate_persona_payloads_byte_identical(): void
    {
        User::factory()->create();
        $alice = User::factory()->approved()->create();
        $ben = User::factory()->approved()->create();
        $separate = Character::factory()->for($ben)->create(['is_linked' => false]);
        $media = Media::factory()->for($ben)->approved()->create(['character_id' => $separate->id]);
        $post = Post::factory()->for($ben)->approved()->create(['character_id' => $separate->id]);
        $story = Story::factory()->for($ben)->readable()->create();
        $story->authors()->where('user_id', $ben->id)->update(['character_id' => $separate->id]);

        $routes = [
            "/api/c/{$separate->ulid}/counts",
            "/api/c/{$separate->ulid}/media",
            "/api/c/{$separate->ulid}/stories",
            "/api/c/{$separate->ulid}/posts",
            "/api/media/by-ulid/{$media->ulid}",
            "/api/posts/by-ulid/{$post->ulid}",
            "/api/stories/by-ulid/{$story->ulid}",
        ];
        $before = $this->payloads($alice, $routes);

        $this->actingAs($alice)->postJson("/api/users/{$ben->id}/block")->assertCreated();

        $this->assertSame($before, $this->payloads($alice, $routes));
    }

    public function test_denial_is_account_wide_regardless_of_the_blocked_accounts_active_persona(): void
    {
        User::factory()->create();
        $alice = User::factory()->approved()->create();
        $ben = User::factory()->approved()->create();
        $benPersonas = Character::factory()->count(2)->for($ben)->create([
            'is_linked' => false,
        ]);
        $aliceSeparate = Character::factory()->for($alice)->create(['is_linked' => false]);
        $accountMedia = Media::factory()->for($alice)->approved()->create();
        $personaPost = Post::factory()->for($alice)->approved()->create([
            'character_id' => $aliceSeparate->id,
        ]);

        $this->actingAs($alice)->postJson("/api/users/{$ben->id}/block")->assertCreated();

        foreach ([null, ...$benPersonas->pluck('id')->all()] as $activeCharacterId) {
            $session = $activeCharacterId === null ? [] : ['active_character_id' => $activeCharacterId];
            $this->actingAs($ben)->withSession($session)
                ->getJson("/api/users/{$alice->id}")
                ->assertNotFound();
            $this->getJson("/api/media/by-ulid/{$accountMedia->ulid}")->assertNotFound();
            $this->getJson("/api/posts/by-ulid/{$personaPost->ulid}")->assertNotFound();
            $this->postJson('/api/favorites', [
                'type' => 'media',
                'id' => $accountMedia->id,
            ])->assertNotFound();
            $this->postJson("/api/posts/{$personaPost->id}/comments", [
                'body' => 'blocked interaction',
            ])->assertNotFound();
            $this->postJson("/api/users/{$alice->id}/follow-requests")->assertNotFound();
        }
    }

    public function test_admin_bypasses_blocks_for_profiles_content_and_batch_gates(): void
    {
        $admin = User::factory()->admin()->create();
        $alice = User::factory()->approved()->create();
        $media = Media::factory()->for($alice)->approved()->create();
        Block::query()->create([
            'blocker_id' => $alice->id,
            'blocked_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/users/{$alice->id}")
            ->assertOk()
            ->assertJsonPath('data.restricted', false);
        $this->assertTrue($media->isViewableBy($admin));
        $this->assertSame([$media->id], Media::query()->viewableBy($admin)->pluck('id')->all());
        $this->getJson('/api/users')->assertOk()->assertJsonFragment(['id' => $alice->id]);
    }

    public function test_block_creation_gives_hidden_and_missing_targets_the_same_generic_404(): void
    {
        User::factory()->create();
        $alice = User::factory()->approved()->create();
        $hiddenUser = User::factory()->approved()->create([
            'profile_audience' => Audience::Followers,
        ]);
        $hiddenPersona = Character::factory()->for($hiddenUser)->audience(Audience::Followers)->create();

        $hiddenUserResponse = $this->actingAs($alice)->postJson("/api/users/{$hiddenUser->id}/block");
        $missingUserResponse = $this->postJson('/api/users/999999/block');
        $hiddenPersonaResponse = $this->postJson("/api/characters/{$hiddenPersona->id}/block");
        $missingPersonaResponse = $this->postJson('/api/characters/999999/block');

        $hiddenUserResponse->assertNotFound();
        $missingUserResponse->assertNotFound();
        $hiddenPersonaResponse->assertNotFound();
        $missingPersonaResponse->assertNotFound();
        $this->assertSame($missingUserResponse->json('message'), $hiddenUserResponse->json('message'));
        $this->assertSame($missingPersonaResponse->json('message'), $hiddenPersonaResponse->json('message'));
        $this->assertDatabaseCount('blocks', 0);
    }

    public function test_blocked_coauthor_hides_a_story_from_direct_and_query_surfaces(): void
    {
        User::factory()->create();
        $alice = User::factory()->approved()->create();
        $owner = User::factory()->approved()->create();
        $ben = User::factory()->approved()->create();
        $kira = Character::factory()->for($ben)->create(['is_linked' => false]);
        $story = Story::factory()->for($owner)->readable()->create();
        $story->authors()->create([
            'user_id' => $ben->id,
            'character_id' => $kira->id,
            'role' => StoryAuthor::ROLE_CO_AUTHOR,
            'status' => StoryAuthor::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        $this->actingAs($alice)
            ->getJson("/api/stories/by-ulid/{$story->ulid}")
            ->assertOk()
            ->assertJsonFragment(['display_name' => $kira->display_name]);

        $this->postJson("/api/characters/{$kira->id}/block")->assertCreated();

        $this->getJson("/api/stories/by-ulid/{$story->ulid}")->assertNotFound();
        $this->getJson('/api/explore/stories')
            ->assertOk()
            ->assertJsonMissing(['ulid' => $story->ulid]);
    }

    public function test_story_authors_bypass_blocks_consistently_in_boolean_and_query_checks(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $coauthor = User::factory()->approved()->create();
        $visitor = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->readable()->create();
        $story->authors()->create([
            'user_id' => $coauthor->id,
            'role' => StoryAuthor::ROLE_CO_AUTHOR,
            'status' => StoryAuthor::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
        Block::query()->create([
            'blocker_id' => $owner->id,
            'blocked_user_id' => $coauthor->id,
        ]);
        Block::query()->create([
            'blocker_id' => $coauthor->id,
            'blocked_user_id' => $owner->id,
        ]);
        Block::query()->create([
            'blocker_id' => $visitor->id,
            'blocked_user_id' => $owner->id,
        ]);

        foreach ([$owner, $coauthor] as $author) {
            $this->assertTrue(BlockGraph::canViewStory($author, $story));
            $this->assertTrue(
                Story::query()->viewableBy($author)->whereKey($story)->exists(),
            );
        }

        $this->assertFalse(BlockGraph::canViewStory($visitor, $story));
        $this->assertFalse(
            Story::query()->viewableBy($visitor)->whereKey($story)->exists(),
        );
    }

    public function test_unblock_by_owned_block_row_does_not_require_the_target_to_remain_visible(): void
    {
        User::factory()->create();
        $alice = User::factory()->approved()->create();
        $ben = User::factory()->approved()->create();

        $response = $this->actingAs($alice)->postJson("/api/users/{$ben->id}/block")->assertCreated();
        $blockId = (int) $response->json('data.block_id');
        $ben->forceFill(['profile_audience' => Audience::Followers])->save();

        $this->deleteJson("/api/blocks/{$blockId}")
            ->assertOk()
            ->assertJsonPath('data.blocked', false);
        $this->assertDatabaseMissing('blocks', ['id' => $blockId]);
        $this->assertDatabaseHas('block_audit_logs', [
            'block_id' => null,
            'action' => BlockAuditLog::ACTION_UNBLOCKED,
        ]);
    }

    public function test_notification_hiding_preserves_the_unblocked_separate_or_account_identity_byte_for_byte(): void
    {
        User::factory()->create();
        $alice = User::factory()->approved()->create();
        $ben = User::factory()->approved()->create();
        $kira = Character::factory()->for($ben)->create(['is_linked' => false]);
        $accountNotification = $this->notification($alice, $ben, null, 'Ben account');
        $this->notification($alice, $ben, $kira, 'Kira persona');

        $accountBefore = $this->notificationRow($alice, $accountNotification->id);
        $this->actingAs($alice)->postJson("/api/characters/{$kira->id}/block")->assertCreated();
        $accountAfter = $this->notificationRow($alice, $accountNotification->id);
        $this->assertSame($accountBefore, $accountAfter);
        $this->getJson('/api/notifications')->assertJsonMissing(['actor_name' => 'Kira persona']);

        $carol = User::factory()->approved()->create();
        $drew = User::factory()->approved()->create();
        $drewPersona = Character::factory()->for($drew)->create(['is_linked' => false]);
        $personaNotification = $this->notification($carol, $drew, $drewPersona, 'Separate Drew');
        $this->notification($carol, $drew, null, 'Drew account');

        $personaBefore = $this->notificationRow($carol, $personaNotification->id);
        $this->postJson("/api/users/{$drew->id}/block")->assertCreated();
        $personaAfter = $this->notificationRow($carol, $personaNotification->id);
        $this->assertSame($personaBefore, $personaAfter);
        $this->getJson('/api/notifications')->assertJsonMissing(['actor_name' => 'Drew account']);
    }

    public function test_notification_denial_is_total_and_counts_and_mark_all_use_the_same_query_veto(): void
    {
        User::factory()->create();
        $alice = User::factory()->approved()->create();
        $alicePersona = Character::factory()->for($alice)->create(['is_linked' => false]);
        $ben = User::factory()->approved()->create();
        $carol = User::factory()->approved()->create();

        $blockedAccount = $this->notification($ben, $alice, null, 'Alice account');
        $blockedPersona = $this->notification($ben, $alice, $alicePersona, 'Alice persona');
        $safe = $this->notification($ben, $carol, null, 'Carol');

        $this->actingAs($alice)->postJson("/api/users/{$ben->id}/block")->assertCreated();

        $this->actingAs($ben)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $safe->id)
            ->assertJsonMissingPath('data.0.data._actor_user_id')
            ->assertJsonMissingPath('data.0.data._actor_character_id');
        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
        $this->postJson('/api/notifications/read-all')->assertOk();

        $this->assertNotNull($safe->refresh()->read_at);
        $this->assertNull($blockedAccount->refresh()->read_at);
        $this->assertNull($blockedPersona->refresh()->read_at);
        $this->postJson("/api/notifications/{$blockedAccount->id}/read")->assertNotFound();
    }

    private function follow(User $requester, User $recipient, ?Character $character = null): FollowRequest
    {
        return FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'recipient_character_id' => $character?->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $routes
     * @return array<string, string>
     */
    private function payloads(User $viewer, array $routes): array
    {
        return collect($routes)->mapWithKeys(function (string $route) use ($viewer): array {
            $response = $this->actingAs($viewer)->getJson($route)->assertOk();

            return [$route => $response->getContent()];
        })->all();
    }

    private function notification(
        User $recipient,
        User $actor,
        ?Character $character,
        string $actorName,
    ): DatabaseNotification {
        return $recipient->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => self::class,
            'data' => [
                'type' => 'test',
                'actor_name' => $actorName,
                '_actor_user_id' => $actor->id,
                '_actor_character_id' => $character?->id,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationRow(User $viewer, string $notificationId): array
    {
        $rows = $this->actingAs($viewer)
            ->getJson('/api/notifications')
            ->assertOk()
            ->json('data');
        $row = collect($rows)->firstWhere('id', $notificationId);
        $this->assertIsArray($row);

        return $row;
    }
}
