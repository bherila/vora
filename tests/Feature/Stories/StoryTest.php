<?php

namespace Tests\Feature\Stories;

use App\Models\Character;
use App\Models\Interest;
use App\Models\Story;
use App\Models\User;
use App\Services\UserAccountService;
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

        $this->actingAs($other)->getJson("/api/stories/{$story->id}")->assertNotFound();
        $this->actingAs($other)->patchJson("/api/stories/{$story->id}", ['title' => 'Hijack'])->assertNotFound();
    }

    public function test_reader_endpoint_requires_published_and_approved_for_non_authors(): void
    {
        $owner = User::factory()->approved()->create();
        $reader = User::factory()->approved()->create();

        $draft = Story::factory()->for($owner)->create();
        $this->actingAs($reader)->getJson("/api/stories/by-ulid/{$draft->ulid}")->assertNotFound();

        $publishedUnreviewed = Story::factory()->for($owner)->published()->create();
        $this->actingAs($reader)->getJson("/api/stories/by-ulid/{$publishedUnreviewed->ulid}")->assertNotFound();

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

        $this->actingAs($coAuthor)->deleteJson("/api/stories/{$story->id}")->assertNotFound();
        $this->actingAs($owner)->deleteJson("/api/stories/{$story->id}")->assertOk();
        $this->assertSoftDeleted('stories', ['id' => $story->id]);
        $this->actingAs($owner)->getJson("/api/stories/by-ulid/{$story->ulid}")->assertNotFound();
    }

    public function test_editing_an_approved_story_returns_it_to_review(): void
    {
        $owner = User::factory()->approved()->create();
        $reader = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->readable()->create(['body' => 'Original']);

        // Readable before the edit.
        $this->actingAs($reader)->getJson("/api/stories/by-ulid/{$story->ulid}")->assertOk();

        $this->actingAs($owner)->patchJson("/api/stories/{$story->id}", ['body' => 'Rewritten after approval'])->assertOk();

        $this->assertSame('pending', $story->refresh()->moderation_status->value);
        // No longer reader-visible until re-approved.
        $this->actingAs($reader)->getJson("/api/stories/by-ulid/{$story->ulid}")->assertNotFound();
    }

    public function test_cyoa_graph_save_returns_an_approved_story_to_review(): void
    {
        $owner = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->cyoa()->readable()->create();

        $this->actingAs($owner)->putJson("/api/stories/{$story->id}/graph", [
            'nodes' => [['key' => 'start', 'body' => 'Begin', 'is_start' => true]],
            'choices' => [],
        ])->assertOk();

        $this->assertSame('pending', $story->refresh()->moderation_status->value);
    }

    public function test_editing_a_rejected_story_returns_it_to_review(): void
    {
        $owner = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->rejected()->create(['body' => 'Original']);

        $this->actingAs($owner)->patchJson("/api/stories/{$story->id}", ['body' => 'Revised after rejection'])->assertOk();

        $this->assertSame('pending', $story->refresh()->moderation_status->value);
    }

    public function test_author_payload_includes_limited_review_status(): void
    {
        $owner = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->published()->rejected()->create([
            'title' => 'Needs work',
            'moderation_notes' => 'Please revise the ending.',
        ]);

        $this->actingAs($owner)->getJson("/api/stories/{$story->id}")
            ->assertOk()
            ->assertJsonPath('data.review.status', 'rejected')
            ->assertJsonPath('data.review.label', 'Rejected')
            ->assertJsonPath('data.review.note', 'Please revise the ending.')
            ->assertJsonMissingPath('data.moderation_status')
            ->assertJsonMissingPath('data.moderated_by_user_id');
    }

    public function test_purging_a_co_author_removes_their_character_involvement_tags(): void
    {
        $owner = User::factory()->approved()->create();
        $coAuthor = User::factory()->approved()->create();
        $character = Character::query()->create(['user_id' => $coAuthor->id, 'display_name' => 'Sidekick']);
        $story = Story::factory()->for($owner)->create();
        $story->authors()->create(['user_id' => $coAuthor->id, 'role' => 'co_author', 'status' => 'accepted', 'responded_at' => now()]);
        $story->involvements()->create(['involvable_type' => 'character', 'involvable_id' => $character->id]);

        app(UserAccountService::class)->purge($coAuthor);

        $this->assertDatabaseMissing('story_involvements', ['involvable_type' => 'character', 'involvable_id' => $character->id]);
        // The owner's story itself survives the co-author's purge.
        $this->assertDatabaseHas('stories', ['id' => $story->id]);
    }

    public function test_reassigning_a_character_owner_drops_now_invalid_involvement_tags(): void
    {
        $owner = User::factory()->approved()->create();
        $newOwner = User::factory()->approved()->create();
        $character = Character::query()->create(['user_id' => $owner->id, 'display_name' => 'Sidekick']);
        $story = Story::factory()->for($owner)->create();
        $story->involvements()->create(['involvable_type' => 'character', 'involvable_id' => $character->id]);

        // Moving the character to a user who does not author the story strands the
        // "involves" tag, so the model invariant prunes it.
        $character->forceFill(['user_id' => $newOwner->id])->save();

        $this->assertDatabaseMissing('story_involvements', ['involvable_type' => 'character', 'involvable_id' => $character->id]);
    }

    public function test_reassigning_a_character_to_a_co_author_keeps_its_involvement_tag(): void
    {
        $owner = User::factory()->approved()->create();
        $coAuthor = User::factory()->approved()->create();
        $character = Character::query()->create(['user_id' => $owner->id, 'display_name' => 'Sidekick']);
        $story = Story::factory()->for($owner)->create();
        $story->authors()->create(['user_id' => $coAuthor->id, 'role' => 'co_author', 'status' => 'accepted', 'responded_at' => now()]);
        $story->involvements()->create(['involvable_type' => 'character', 'involvable_id' => $character->id]);

        // The new owner is an accepted author of this story, so the character tag is
        // still valid and must be kept rather than blanket-deleted.
        $character->forceFill(['user_id' => $coAuthor->id])->save();

        $this->assertDatabaseHas('story_involvements', ['involvable_type' => 'character', 'involvable_id' => $character->id]);
    }

    public function test_story_from_disabled_owner_is_hidden_from_readers(): void
    {
        $owner = User::factory()->approved()->create();
        $reader = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->readable()->create();

        $this->actingAs($reader)->getJson("/api/stories/by-ulid/{$story->ulid}")->assertOk();

        $owner->forceFill(['is_disabled' => true])->save();
        $this->actingAs($reader)->getJson("/api/stories/by-ulid/{$story->ulid}")->assertNotFound();
    }

    public function test_story_from_ban_hidden_owner_is_hidden_on_direct_reads(): void
    {
        $owner = User::factory()->approved()->create();
        $reader = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->readable()->create();

        $this->actingAs($reader)->getJson("/api/stories/by-ulid/{$story->ulid}")->assertOk();

        // Ban + hide content: the direct ULID read path must respect isActive().
        $owner->forceFill(['banned_at' => now(), 'ban_hides_content' => true])->save();
        $this->actingAs($reader)->getJson("/api/stories/by-ulid/{$story->ulid}")->assertNotFound();

        // Ban that keeps content visible (memorialized) does not hide the story.
        $owner->forceFill(['ban_hides_content' => false])->save();
        $this->actingAs($reader)->getJson("/api/stories/by-ulid/{$story->ulid}")->assertOk();
    }

    public function test_reader_payload_hides_deactivated_co_authors_and_their_tags(): void
    {
        $owner = User::factory()->approved()->create();
        $coAuthor = User::factory()->approved()->create(['display_name' => 'Collab']);
        $reader = User::factory()->approved()->create();
        $coCharacter = Character::query()->create(['user_id' => $coAuthor->id, 'display_name' => 'Sidekick']);

        $story = Story::factory()->for($owner)->readable()->create();
        $story->authors()->create(['user_id' => $coAuthor->id, 'role' => 'co_author', 'status' => 'accepted', 'responded_at' => now()]);
        $story->involvements()->create(['involvable_type' => 'character', 'involvable_id' => $coCharacter->id]);
        $story->involvements()->create(['involvable_type' => 'user', 'involvable_id' => $coAuthor->id]);

        // While active, the co-author and their character appear.
        $this->actingAs($reader)->getJson("/api/stories/by-ulid/{$story->ulid}")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Sidekick']);

        $coAuthor->forceFill(['deactivated_at' => now()])->save();

        $response = $this->actingAs($reader)->getJson("/api/stories/by-ulid/{$story->ulid}")->assertOk();
        $authorIds = collect($response->json('data.authors'))->pluck('user_id');
        $this->assertNotContains($coAuthor->id, $authorIds->all());
        $involveNames = collect($response->json('data.involves'))->pluck('name');
        $this->assertNotContains('Sidekick', $involveNames->all());
        $this->assertNotContains('Collab', $involveNames->all());
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

    public function test_stories_page_without_an_edit_target_redirects_to_profile(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->get('/stories')->assertRedirect(route('me'));
    }

    public function test_stories_page_renders_the_editor_for_an_edit_target(): void
    {
        $user = User::factory()->approved()->create();
        $story = Story::factory()->for($user)->create(['title' => 'Draft']);

        $this->actingAs($user)->get("/stories?edit={$story->id}")
            ->assertOk()
            ->assertSee('stories-app', false);
    }

    public function test_index_lists_owner_drafts_for_the_profile_stories_tab(): void
    {
        $user = User::factory()->approved()->create();
        Story::factory()->for($user)->create(['title' => 'A Draft']);

        $titles = collect($this->actingAs($user)->getJson('/api/stories')->assertOk()->json('data'))->pluck('title');
        $this->assertContains('A Draft', $titles->all());
    }

    public function test_hidden_and_missing_story_ids_are_indistinguishable(): void
    {
        $owner = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create(); // private draft, not visible to $other

        // A hidden-but-existing story and a never-existed id must answer with the
        // same 404 body, so a sequential id scan can't tell real ids from fake ones.
        $hidden = $this->actingAs($other)->getJson("/api/stories/{$story->id}")->assertNotFound();
        $missing = $this->actingAs($other)->getJson('/api/stories/999999')->assertNotFound();

        $this->assertSame('Not found.', $hidden->json('message'));
        $this->assertSame($missing->json('message'), $hidden->json('message'));
    }
}
