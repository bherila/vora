<?php

namespace Tests\Feature\Stories;

use App\Models\Character;
use App\Models\Story;
use App\Models\StoryAuthor;
use App\Models\User;
use App\Notifications\CoAuthorInviteAccepted;
use App\Notifications\CoAuthorInviteReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CoAuthorTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_invites_co_author_who_accepts_via_shared_inbox(): void
    {
        Notification::fake();
        $owner = User::factory()->approved()->create();
        $invitee = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create();

        $this->actingAs($owner)->postJson("/api/stories/{$story->id}/authors", ['user_id' => $invitee->id])
            ->assertCreated();
        Notification::assertSentTo($invitee, CoAuthorInviteReceived::class);

        $invite = StoryAuthor::query()->where('story_id', $story->id)->where('user_id', $invitee->id)->firstOrFail();
        $this->assertSame('pending', $invite->status);

        // Appears in the consolidated invite inbox + count.
        $this->actingAs($invitee)->getJson('/api/authorship-invites')
            ->assertOk()
            ->assertJsonPath('data.0.story.title', $story->title);
        $this->actingAs($invitee)->getJson('/api/authorship-invites/count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->actingAs($invitee)->postJson("/api/authorship-invites/{$invite->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');
        Notification::assertSentTo($owner, CoAuthorInviteAccepted::class);

        // Now an author, the co-author can edit.
        $this->actingAs($invitee)->patchJson("/api/stories/{$story->id}", ['title' => 'Edited together'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Edited together');
    }

    public function test_pending_co_author_cannot_edit_until_accepted(): void
    {
        $owner = User::factory()->approved()->create();
        $invitee = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create();
        $story->authors()->create(['user_id' => $invitee->id, 'role' => 'co_author', 'status' => 'pending']);

        $this->actingAs($invitee)->patchJson("/api/stories/{$story->id}", ['title' => 'Nope'])->assertForbidden();
    }

    public function test_declining_removes_the_invite(): void
    {
        $owner = User::factory()->approved()->create();
        $invitee = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create();
        $invite = $story->authors()->create(['user_id' => $invitee->id, 'role' => 'co_author', 'status' => 'pending']);

        $this->actingAs($invitee)->postJson("/api/authorship-invites/{$invite->id}/decline")
            ->assertOk()
            ->assertJsonPath('data.status', 'declined');
        $this->assertDatabaseMissing('story_authors', ['id' => $invite->id]);
    }

    public function test_co_author_can_leave_and_owner_can_remove(): void
    {
        $owner = User::factory()->approved()->create();
        $coAuthor = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create();
        $story->authors()->create(['user_id' => $coAuthor->id, 'role' => 'co_author', 'status' => 'accepted', 'responded_at' => now()]);

        // Co-author leaves.
        $this->actingAs($coAuthor)->deleteJson("/api/stories/{$story->id}/authors/{$coAuthor->id}")->assertOk();
        $this->assertDatabaseMissing('story_authors', ['story_id' => $story->id, 'user_id' => $coAuthor->id]);

        // Re-add then owner removes.
        $story->authors()->create(['user_id' => $coAuthor->id, 'role' => 'co_author', 'status' => 'accepted', 'responded_at' => now()]);
        $this->actingAs($owner)->deleteJson("/api/stories/{$story->id}/authors/{$coAuthor->id}")->assertOk();
        $this->assertDatabaseMissing('story_authors', ['story_id' => $story->id, 'user_id' => $coAuthor->id]);
    }

    public function test_removing_a_co_author_clears_their_involvement_tags(): void
    {
        $owner = User::factory()->approved()->create();
        $coAuthor = User::factory()->approved()->create();
        $coCharacter = Character::query()->create(['user_id' => $coAuthor->id, 'display_name' => 'Sidekick']);
        $story = Story::factory()->for($owner)->create();
        $story->authors()->create(['user_id' => $coAuthor->id, 'role' => 'co_author', 'status' => 'accepted', 'responded_at' => now()]);
        $story->involvements()->create(['involvable_type' => 'user', 'involvable_id' => $coAuthor->id]);
        $story->involvements()->create(['involvable_type' => 'character', 'involvable_id' => $coCharacter->id]);
        $story->involvements()->create(['involvable_type' => 'user', 'involvable_id' => $owner->id]);

        $this->actingAs($owner)->deleteJson("/api/stories/{$story->id}/authors/{$coAuthor->id}")->assertOk();

        // The removed co-author's tags are gone; the owner's own tag remains.
        $this->assertDatabaseMissing('story_involvements', ['story_id' => $story->id, 'involvable_type' => 'user', 'involvable_id' => $coAuthor->id]);
        $this->assertDatabaseMissing('story_involvements', ['story_id' => $story->id, 'involvable_type' => 'character', 'involvable_id' => $coCharacter->id]);
        $this->assertDatabaseHas('story_involvements', ['story_id' => $story->id, 'involvable_type' => 'user', 'involvable_id' => $owner->id]);
    }

    public function test_navbar_request_count_excludes_invites_from_inactive_owners(): void
    {
        $owner = User::factory()->approved()->create();
        $invitee = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create();
        $story->authors()->create(['user_id' => $invitee->id, 'role' => 'co_author', 'status' => 'pending']);

        // The navbar bootstrap JSON escapes quotes (JSON_HEX_QUOT), so match the
        // escaped key form (" for ").
        $this->actingAs($invitee)->get('/dashboard')->assertSee('requestCount":1', false);

        $owner->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($invitee)->get('/dashboard')->assertSee('requestCount":0', false);
    }

    public function test_owner_cannot_be_removed_and_non_owner_cannot_invite(): void
    {
        $owner = User::factory()->approved()->create();
        $coAuthor = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create();
        $story->authors()->create(['user_id' => $coAuthor->id, 'role' => 'co_author', 'status' => 'accepted', 'responded_at' => now()]);

        $this->actingAs($owner)->deleteJson("/api/stories/{$story->id}/authors/{$owner->id}")->assertStatus(422);

        $third = User::factory()->approved()->create();
        $this->actingAs($coAuthor)->postJson("/api/stories/{$story->id}/authors", ['user_id' => $third->id])->assertForbidden();
    }

    public function test_owner_can_remove_a_co_author_who_deleted_their_account(): void
    {
        $owner = User::factory()->approved()->create();
        $coAuthor = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create();
        $story->authors()->create(['user_id' => $coAuthor->id, 'role' => 'co_author', 'status' => 'accepted', 'responded_at' => now()]);

        $coAuthor->delete(); // soft delete

        $this->actingAs($owner)->deleteJson("/api/stories/{$story->id}/authors/{$coAuthor->id}")->assertOk();
        $this->assertDatabaseMissing('story_authors', ['story_id' => $story->id, 'user_id' => $coAuthor->id]);
    }

    public function test_invite_cannot_be_accepted_after_owner_deactivates(): void
    {
        $owner = User::factory()->approved()->create();
        $invitee = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create();
        $invite = $story->authors()->create(['user_id' => $invitee->id, 'role' => 'co_author', 'status' => 'pending']);

        $owner->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($invitee)->postJson("/api/authorship-invites/{$invite->id}/accept")->assertNotFound();
        $this->assertSame('pending', $invite->refresh()->status);
    }

    public function test_invites_from_inactive_owners_are_hidden_from_the_inbox(): void
    {
        $owner = User::factory()->approved()->create();
        $invitee = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create();
        $story->authors()->create(['user_id' => $invitee->id, 'role' => 'co_author', 'status' => 'pending']);

        $this->actingAs($invitee)->getJson('/api/authorship-invites')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($invitee)->getJson('/api/authorship-invites/count')->assertOk()->assertJsonPath('data.count', 1);

        $owner->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($invitee)->getJson('/api/authorship-invites')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($invitee)->getJson('/api/authorship-invites/count')->assertOk()->assertJsonPath('data.count', 0);
    }

    public function test_non_author_cannot_probe_authorship_via_remove_endpoint(): void
    {
        $owner = User::factory()->approved()->create();
        $coAuthor = User::factory()->approved()->create();
        $stranger = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create();
        $story->authors()->create(['user_id' => $coAuthor->id, 'role' => 'co_author', 'status' => 'accepted', 'responded_at' => now()]);

        // A non-author gets a uniform 403 before any authorship row is disclosed.
        $this->actingAs($stranger)->deleteJson("/api/stories/{$story->id}/authors/{$coAuthor->id}")->assertForbidden();
        $this->assertDatabaseHas('story_authors', ['story_id' => $story->id, 'user_id' => $coAuthor->id]);
    }

    public function test_cannot_invite_same_user_twice(): void
    {
        $owner = User::factory()->approved()->create();
        $invitee = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create();

        $this->actingAs($owner)->postJson("/api/stories/{$story->id}/authors", ['user_id' => $invitee->id])->assertCreated();
        $this->actingAs($owner)->postJson("/api/stories/{$story->id}/authors", ['user_id' => $invitee->id])->assertStatus(422);
    }

    public function test_involvable_options_exclude_inactive_co_authors(): void
    {
        $owner = User::factory()->approved()->create();
        $active = User::factory()->approved()->create();
        $disabled = User::factory()->approved()->create();
        $disabledCharacter = Character::query()->create(['user_id' => $disabled->id, 'display_name' => 'Ghost']);
        $story = Story::factory()->for($owner)->create();
        $story->authors()->create(['user_id' => $active->id, 'role' => 'co_author', 'status' => 'accepted', 'responded_at' => now()]);
        $story->authors()->create(['user_id' => $disabled->id, 'role' => 'co_author', 'status' => 'accepted', 'responded_at' => now()]);
        $disabled->forceFill(['is_disabled' => true])->save();

        $response = $this->actingAs($owner)->getJson("/api/stories/{$story->id}")->assertOk();
        $keys = collect($response->json('data.involvable_options'))
            ->map(fn (array $o): string => $o['type'].':'.$o['id'])
            ->all();

        // The disabled co-author and their characters drop out of the picker.
        $this->assertContains('user:'.$active->id, $keys);
        $this->assertNotContains('user:'.$disabled->id, $keys);
        $this->assertNotContains('character:'.$disabledCharacter->id, $keys);
    }

    public function test_summary_hides_inactive_co_author_involvements_and_authorship(): void
    {
        $owner = User::factory()->approved()->create();
        $coAuthor = User::factory()->approved()->create();
        $coCharacter = Character::query()->create(['user_id' => $coAuthor->id, 'display_name' => 'Sidekick']);
        $story = Story::factory()->for($owner)->create();
        $story->authors()->create(['user_id' => $coAuthor->id, 'role' => 'co_author', 'status' => 'accepted', 'responded_at' => now()]);
        $story->involvements()->create(['involvable_type' => 'user', 'involvable_id' => $coAuthor->id]);
        $story->involvements()->create(['involvable_type' => 'character', 'involvable_id' => $coCharacter->id]);
        $story->involvements()->create(['involvable_type' => 'user', 'involvable_id' => $owner->id]);

        $coAuthor->forceFill(['is_disabled' => true])->save();

        $response = $this->actingAs($owner)->getJson('/api/stories')->assertOk();
        $involves = collect($response->json('data.0.involves'))
            ->map(fn (array $o): string => $o['type'].':'.$o['id'])
            ->all();
        $authorUserIds = collect($response->json('data.0.authors'))->pluck('user_id')->all();

        // The owner's own tag and authorship survive; the disabled co-author's
        // involvement tags and authorship entry are filtered out of the listing.
        $this->assertContains('user:'.$owner->id, $involves);
        $this->assertNotContains('user:'.$coAuthor->id, $involves);
        $this->assertNotContains('character:'.$coCharacter->id, $involves);
        $this->assertContains($owner->id, $authorUserIds);
        $this->assertNotContains($coAuthor->id, $authorUserIds);
    }
}
