<?php

namespace Tests\Feature\Stories;

use App\Models\Character;
use App\Models\Interest;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_a_long_form_story_with_tags(): void
    {
        $user = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Adventure']);
        $character = Character::query()->create(['user_id' => $user->id, 'display_name' => 'Hero']);

        $response = $this->actingAs($user)->postJson('/api/stories', [
            'title' => 'My Tale',
            'type' => 'long_form',
            'body' => '# Hello',
            'interest_ids' => [$interest->id],
            'involvements' => [
                ['type' => 'user', 'id' => $user->id],
                ['type' => 'character', 'id' => $character->id],
            ],
        ])->assertCreated();

        $response->assertJsonPath('data.title', 'My Tale');
        $response->assertJsonPath('data.interests.0.name', 'Adventure');
        $this->assertCount(2, $response->json('data.involves'));

        $story = Story::query()->firstOrFail();
        $this->assertTrue($story->isAuthoredBy($user));
        $this->assertDatabaseHas('story_authors', ['story_id' => $story->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'accepted']);
    }

    public function test_involvements_are_limited_to_authors_and_their_characters(): void
    {
        $user = User::factory()->approved()->create();
        $stranger = User::factory()->approved()->create();
        $strangerCharacter = Character::query()->create(['user_id' => $stranger->id, 'display_name' => 'Outsider']);

        $story = Story::factory()->for($user)->create();

        $this->actingAs($user)->patchJson("/api/stories/{$story->id}", [
            'involvements' => [
                ['type' => 'user', 'id' => $stranger->id],
                ['type' => 'character', 'id' => $strangerCharacter->id],
            ],
        ])->assertOk()->assertJsonPath('data.involves', []);

        $this->assertDatabaseCount('story_involvements', 0);
    }

    public function test_non_author_cannot_view_draft_or_edit(): void
    {
        $owner = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create();

        $this->actingAs($other)->getJson("/api/stories/{$story->id}")->assertForbidden();
        $this->actingAs($other)->patchJson("/api/stories/{$story->id}", ['title' => 'Hijack'])->assertForbidden();
    }

    public function test_reader_endpoint_requires_published_and_approved_for_non_authors(): void
    {
        $owner = User::factory()->approved()->create();
        $reader = User::factory()->approved()->create();

        $draft = Story::factory()->for($owner)->create();
        $this->actingAs($reader)->getJson("/api/stories/by-ulid/{$draft->ulid}")->assertForbidden();

        $publishedUnreviewed = Story::factory()->for($owner)->published()->create();
        $this->actingAs($reader)->getJson("/api/stories/by-ulid/{$publishedUnreviewed->ulid}")->assertForbidden();

        $readable = Story::factory()->for($owner)->readable()->create(['body' => 'Once upon a time']);
        $this->actingAs($reader)->getJson("/api/stories/by-ulid/{$readable->ulid}")
            ->assertOk()
            ->assertJsonPath('data.body', 'Once upon a time');
    }

    public function test_author_can_always_read_own_draft_by_ulid(): void
    {
        $owner = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create();

        $this->actingAs($owner)->getJson("/api/stories/by-ulid/{$story->ulid}")->assertOk();
    }

    public function test_publishing_sets_published_at(): void
    {
        $owner = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create();

        $this->actingAs($owner)->patchJson("/api/stories/{$story->id}", ['status' => 'published'])->assertOk();

        $this->assertNotNull($story->refresh()->published_at);
    }

    public function test_only_owner_can_delete(): void
    {
        $owner = User::factory()->approved()->create();
        $coAuthor = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create();
        $story->authors()->create(['user_id' => $coAuthor->id, 'role' => 'co_author', 'status' => 'accepted', 'responded_at' => now()]);

        $this->actingAs($coAuthor)->deleteJson("/api/stories/{$story->id}")->assertForbidden();
        $this->actingAs($owner)->deleteJson("/api/stories/{$story->id}")->assertOk();
        $this->assertDatabaseMissing('stories', ['id' => $story->id]);
    }

    public function test_library_lists_owned_and_co_authored_stories(): void
    {
        $owner = User::factory()->approved()->create();
        $coAuthor = User::factory()->approved()->create();
        $owned = Story::factory()->for($owner)->create(['title' => 'Mine']);
        $collab = Story::factory()->for($owner)->create(['title' => 'Ours']);
        $collab->authors()->create(['user_id' => $coAuthor->id, 'role' => 'co_author', 'status' => 'accepted', 'responded_at' => now()]);
        Story::factory()->for($coAuthor)->create(['title' => 'Pending'])
            ->authors()->where('user_id', $owner->id); // unrelated to owner

        $titles = collect($this->actingAs($owner)->getJson('/api/stories')->assertOk()->json('data'))->pluck('title');
        $this->assertEqualsCanonicalizing(['Mine', 'Ours'], $titles->all());

        $coTitles = collect($this->actingAs($coAuthor)->getJson('/api/stories')->assertOk()->json('data'))->pluck('title');
        $this->assertContains('Ours', $coTitles->all());
        $this->assertContains('Pending', $coTitles->all());
        $this->assertNotContains('Mine', $coTitles->all());
    }
}
