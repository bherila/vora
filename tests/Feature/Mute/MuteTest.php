<?php

namespace Tests\Feature\Mute;

use App\Enums\Audience;
use App\Jobs\NotifyFollowersOfPost;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Media;
use App\Models\Mute;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MuteTest extends TestCase
{
    use RefreshDatabase;

    private function follow(User $follower, User $followee, ?Character $character = null): FollowRequest
    {
        return FollowRequest::query()->create([
            'requester_id' => $follower->id,
            'recipient_id' => $followee->id,
            'recipient_character_id' => $character?->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function initialData(string $html): array
    {
        preg_match('/<script id="initial-data"[^>]*>\s*(.*?)\s*<\/script>/s', $html, $matches);

        $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        return $decoded['initialData'] ?? $decoded;
    }

    /** @return list<string> */
    private function feedUlids(User $viewer): array
    {
        return collect($this->actingAs($viewer)->getJson('/api/feed?scope=mixed')->assertOk()->json('data'))
            ->pluck('ulid')
            ->all();
    }

    public function test_mute_endpoints_store_exact_targets_and_settings_never_resolve_a_separate_persona_owner(): void
    {
        User::factory()->create();
        $viewer = User::factory()->approved()->create();
        $owner = User::factory()->approved()->create([
            'display_name' => 'Owner Sentinel',
            'name' => 'Owner Sentinel',
        ]);
        $separate = Character::factory()->for($owner)->create([
            'display_name' => 'Kira',
            'is_linked' => false,
        ]);

        $this->actingAs($viewer)->postJson('/api/mutes', [
            'type' => 'user',
            'id' => $owner->id,
        ])->assertCreated()->assertJsonPath('data.muted', true);
        $this->actingAs($viewer)->postJson('/api/mutes', [
            'type' => 'character',
            'id' => $separate->id,
        ])->assertCreated();

        $this->assertDatabaseHas('mutes', [
            'user_id' => $viewer->id,
            'muted_user_id' => $owner->id,
            'muted_character_id' => null,
        ]);
        $this->assertDatabaseHas('mutes', [
            'user_id' => $viewer->id,
            'muted_user_id' => null,
            'muted_character_id' => $separate->id,
        ]);

        $json = $this->actingAs($viewer)->getJson('/api/mutes')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->json();
        $persona = collect($json['data'])->firstWhere('type', 'character');
        $this->assertSame('Kira', $persona['display_name']);
        $this->assertSame("/c/{$separate->ulid}", $persona['profile_url']);
        $this->assertStringNotContainsString('Owner Sentinel', json_encode($persona, JSON_THROW_ON_ERROR));

        $this->actingAs($viewer)->getJson('/api/account/export')
            ->assertOk()
            ->assertJsonFragment([
                'muted_user_id' => $owner->id,
                'muted_character_id' => null,
            ])
            ->assertJsonFragment([
                'muted_user_id' => null,
                'muted_character_id' => $separate->id,
            ]);

        $this->actingAs($viewer)->postJson('/api/mutes', [
            'type' => 'user',
            'id' => $viewer->id,
        ])->assertUnprocessable();
        $this->actingAs($viewer)->postJson('/api/mutes', [
            'type' => 'character',
            'id' => 999999,
        ])->assertNotFound();
    }

    public function test_store_uses_generic_visibility_checks_but_destroy_remains_idempotent_after_target_hides(): void
    {
        User::factory()->create();
        $viewer = User::factory()->approved()->create();
        $owner = User::factory()->approved()->create();
        $hidden = Character::factory()->for($owner)->audience(Audience::Followers)->create();

        $this->actingAs($viewer)->postJson('/api/mutes', [
            'type' => 'character',
            'id' => $hidden->id,
        ])->assertNotFound();

        $this->actingAs($viewer)->postJson('/api/mutes', [
            'type' => 'user',
            'id' => $owner->id,
        ])->assertCreated();
        $owner->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($viewer)->deleteJson('/api/mutes', [
            'type' => 'user',
            'id' => $owner->id,
        ])->assertOk()->assertJsonPath('data.muted', false);
        $this->actingAs($viewer)->deleteJson('/api/mutes', [
            'type' => 'user',
            'id' => $owner->id,
        ])->assertOk();
        $this->assertDatabaseMissing('mutes', ['user_id' => $viewer->id]);
    }

    public function test_mute_never_cascades_and_follow_edges_survive(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $linked = Character::factory()->for($owner)->create(['is_linked' => true]);
        $separate = Character::factory()->for($owner)->create(['is_linked' => false]);
        $follow = $this->follow($viewer, $owner);

        $humanPost = Post::factory()->for($owner)->approved()->create();
        $linkedPost = Post::factory()->for($owner)->approved()->create(['character_id' => $linked->id]);
        $separatePost = Post::factory()->for($owner)->approved()->create(['character_id' => $separate->id]);

        $this->actingAs($viewer)->postJson('/api/mutes', ['type' => 'user', 'id' => $owner->id])->assertCreated();
        $ulids = $this->feedUlids($viewer);
        $this->assertNotContains($humanPost->ulid, $ulids);
        $this->assertContains($linkedPost->ulid, $ulids);
        $this->assertContains($separatePost->ulid, $ulids);
        $this->assertDatabaseHas('follow_requests', ['id' => $follow->id]);

        $this->actingAs($viewer)->postJson('/api/mutes', ['type' => 'character', 'id' => $linked->id])->assertCreated();
        $ulids = $this->feedUlids($viewer);
        $this->assertNotContains($humanPost->ulid, $ulids);
        $this->assertNotContains($linkedPost->ulid, $ulids);
        $this->assertContains($separatePost->ulid, $ulids);

        $this->actingAs($viewer)->deleteJson('/api/mutes', ['type' => 'user', 'id' => $owner->id])->assertOk();
        $this->assertContains($humanPost->ulid, $this->feedUlids($viewer));
    }

    public function test_feed_filters_mutes_before_cursor_pagination(): void
    {
        User::factory()->create();
        $viewer = User::factory()->approved()->create();
        $visibleAuthor = User::factory()->approved()->create();
        $mutedAuthor = User::factory()->approved()->create();
        $pageSize = (int) config('media.page_size', 24);

        Post::factory()->for($visibleAuthor)->approved()->count($pageSize + 2)->create();
        Post::factory()->for($mutedAuthor)->approved()->count($pageSize)->create();
        Mute::query()->create(['user_id' => $viewer->id, 'muted_user_id' => $mutedAuthor->id]);

        $first = $this->actingAs($viewer)->getJson('/api/feed?scope=mixed')->assertOk();
        $this->assertCount($pageSize, $first->json('data'));
        $this->assertNotNull($first->json('next_cursor'));
        $this->assertNotContains(
            $mutedAuthor->id,
            collect($first->json('data'))->pluck('author.id')->filter()->all(),
        );
    }

    public function test_people_and_explore_filter_only_the_muted_identity(): void
    {
        User::factory()->create();
        $viewer = User::factory()->approved()->create();
        $owner = User::factory()->approved()->create(['display_name' => 'Ben']);
        $persona = Character::factory()->for($owner)->create([
            'display_name' => 'Kira',
            'is_linked' => true,
        ]);
        $humanMedia = Media::factory()->for($owner)->approved()->create(['title' => 'Human media']);
        $personaMedia = Media::factory()->for($owner)->approved()->create([
            'character_id' => $persona->id,
            'title' => 'Persona media',
        ]);
        $humanStory = Story::factory()->for($owner)->published()->approved()->create(['title' => 'Human story']);
        $personaStory = Story::factory()->for($owner)->published()->approved()->create(['title' => 'Persona story']);
        $personaStory->authors()->where('user_id', $owner->id)->update(['character_id' => $persona->id]);

        Mute::query()->create(['user_id' => $viewer->id, 'muted_user_id' => $owner->id]);

        $people = $this->initialData($this->actingAs($viewer)->get('/users')->assertOk()->getContent());
        $this->assertNotContains($owner->id, collect($people['followDirectory'])->pluck('id')->all());
        $this->assertContains($persona->id, collect($people['followDirectoryPersonas'])->pluck('id')->all());
        $this->actingAs($viewer)->getJson('/api/explore')
            ->assertOk()
            ->assertJsonMissing(['id' => $humanMedia->id])
            ->assertJsonFragment(['id' => $personaMedia->id]);
        $this->actingAs($viewer)->getJson('/api/explore/stories')
            ->assertOk()
            ->assertJsonMissing(['id' => $humanStory->id])
            ->assertJsonFragment(['id' => $personaStory->id]);

        Mute::query()->create(['user_id' => $viewer->id, 'muted_character_id' => $persona->id]);
        $this->actingAs($viewer)->getJson('/api/explore/personas')
            ->assertOk()
            ->assertJsonMissing(['id' => $persona->id]);
        $this->actingAs($viewer)->getJson('/api/explore')
            ->assertOk()
            ->assertJsonMissing(['id' => $personaMedia->id]);
    }

    public function test_direct_profiles_and_content_stay_visible_with_muted_indicator(): void
    {
        User::factory()->create();
        $viewer = User::factory()->approved()->create();
        $owner = User::factory()->approved()->create();
        $persona = Character::factory()->for($owner)->create(['is_linked' => false]);
        $humanPost = Post::factory()->for($owner)->approved()->create();
        $personaPost = Post::factory()->for($owner)->approved()->create(['character_id' => $persona->id]);
        Mute::query()->create(['user_id' => $viewer->id, 'muted_user_id' => $owner->id]);
        Mute::query()->create(['user_id' => $viewer->id, 'muted_character_id' => $persona->id]);

        $profile = $this->initialData(
            $this->actingAs($viewer)->get("/users/{$owner->id}")->assertOk()->getContent(),
        );
        $this->assertTrue($profile['followProfile']['viewer_muted']);
        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}/posts")
            ->assertOk()
            ->assertJsonFragment(['ulid' => $humanPost->ulid]);

        $personaProfile = $this->initialData(
            $this->actingAs($viewer)->get("/c/{$persona->ulid}")->assertOk()->getContent(),
        );
        $this->assertTrue($personaProfile['personaProfile']['viewer_muted']);
        $this->actingAs($viewer)->getJson("/api/c/{$persona->ulid}/posts")
            ->assertOk()
            ->assertJsonFragment(['ulid' => $personaPost->ulid]);
    }

    public function test_side_rail_listings_hide_mutes_without_erasing_reversible_history(): void
    {
        User::factory()->create();
        $viewer = User::factory()->approved()->create();
        $target = User::factory()->approved()->create(['display_name' => 'Muted Trail']);

        $this->actingAs($viewer)->get("/users/{$target->id}")->assertOk();
        $this->actingAs($viewer)->getJson('/api/side-rail')
            ->assertOk()
            ->assertJsonFragment(['display_name' => 'Muted Trail']);

        Mute::query()->create(['user_id' => $viewer->id, 'muted_user_id' => $target->id]);
        $this->actingAs($viewer)->getJson('/api/side-rail')
            ->assertOk()
            ->assertJsonMissing(['display_name' => 'Muted Trail']);

        Mute::query()->where('user_id', $viewer->id)->delete();
        $this->actingAs($viewer)->getJson('/api/side-rail')
            ->assertOk()
            ->assertJsonFragment(['display_name' => 'Muted Trail']);
    }

    public function test_muted_comments_remain_visible_inline_as_recorded_on_the_issue(): void
    {
        User::factory()->create();
        $author = User::factory()->approved()->create();
        $commenter = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();

        $this->actingAs($commenter)->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'Context-preserving reply',
        ])->assertCreated();
        Mute::query()->create(['user_id' => $author->id, 'muted_user_id' => $commenter->id]);

        $this->actingAs($author)->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonFragment(['body' => 'Context-preserving reply']);
    }

    public function test_notifications_are_suppressed_and_existing_rows_are_hidden_in_query(): void
    {
        User::factory()->create();
        $author = User::factory()->approved()->create();
        $actor = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();

        $this->actingAs($actor)->postJson("/api/posts/{$post->id}/reactions")->assertOk();
        $this->assertSame(1, $author->notifications()->count());

        Mute::query()->create(['user_id' => $author->id, 'muted_user_id' => $actor->id]);
        $this->actingAs($author)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->actingAs($author)->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 0);

        $secondPost = Post::factory()->for($author)->approved()->create();
        $this->actingAs($actor)->postJson("/api/posts/{$secondPost->id}/reactions")->assertOk();
        $this->assertSame(1, $author->notifications()->count(), 'muted actor produces no new notification');

        Mute::query()->where('user_id', $author->id)->delete();
        $this->actingAs($author)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_human_mute_does_not_suppress_a_linked_persona_notification(): void
    {
        User::factory()->create();
        $author = User::factory()->approved()->create();
        $follower = User::factory()->approved()->create();
        $linked = Character::factory()->for($author)->create(['is_linked' => true]);
        $this->follow($follower, $author);
        Mute::query()->create(['user_id' => $follower->id, 'muted_user_id' => $author->id]);

        $visiblePersonaPost = Post::factory()->for($author)->approved()->create([
            'character_id' => $linked->id,
        ]);
        (new NotifyFollowersOfPost($visiblePersonaPost))->handle();

        $this->assertSame(1, $follower->notifications()->count());
        $this->actingAs($follower)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.data.actor_character_id', $linked->id);

        Mute::query()->create(['user_id' => $follower->id, 'muted_character_id' => $linked->id]);
        $mutedPersonaPost = Post::factory()->for($author)->approved()->create([
            'character_id' => $linked->id,
        ]);
        (new NotifyFollowersOfPost($mutedPersonaPost))->handle();

        $this->assertSame(1, $follower->notifications()->count());
        $this->actingAs($follower)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data', 'the older persona notification also hides once its exact identity is muted');
    }
}
