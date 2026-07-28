<?php

namespace Tests\Feature\Profile;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Media;
use App\Models\Story;
use App\Models\StoryAuthor;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ProfileContentTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStorage(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
        });
    }

    private function follow(User $follower, User $owner): void
    {
        FollowRequest::query()->create([
            'requester_id' => $follower->id,
            'recipient_id' => $owner->id,
            'status' => 'accepted',
        ]);
    }

    public function test_profile_media_is_scoped_to_the_selected_identity(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create();

        $mainMedia = Media::factory()->for($owner)->approved()->create(['title' => 'Main']);
        $characterMedia = Media::factory()->for($owner)->approved()->create(['title' => 'Char', 'character_id' => $character->id]);

        // Main identity: only character-less media.
        $this->actingAs($owner)->getJson("/api/users/{$owner->id}/media")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mainMedia->id);

        // Character identity: only that character's media.
        $this->actingAs($owner)->getJson("/api/users/{$owner->id}/media?character_id={$character->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $characterMedia->id);
    }

    public function test_profile_media_hides_items_the_viewer_cannot_see(): void
    {
        $this->fakeStorage();
        User::factory()->create(); // spacer so the viewer is not the admin (id 1)
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();

        $public = Media::factory()->for($owner)->approved()->create(['title' => 'Public']);
        Media::factory()->for($owner)->approved()->audience(Audience::Followers)->create(['title' => 'Followers']);

        // The viewer does not follow the owner, so only the Everyone item shows.
        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}/media")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $public->id);

        // After following, the followers-only item appears too.
        $this->follow($viewer, $owner);
        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}/media")
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_profile_content_requires_profile_visibility(): void
    {
        $this->fakeStorage();
        User::factory()->create();
        $owner = User::factory()->approved()->create(['profile_audience' => Audience::Followers]);
        $viewer = User::factory()->approved()->create();

        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}/media")->assertForbidden();
        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}/stories")->assertForbidden();
        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}/posts")->assertForbidden();
    }

    public function test_a_foreign_character_id_is_not_found(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $stranger = User::factory()->approved()->create();
        $foreignCharacter = Character::factory()->for($stranger)->create();

        $this->actingAs($owner)->getJson("/api/users/{$owner->id}/media?character_id={$foreignCharacter->id}")
            ->assertNotFound();
    }

    public function test_profile_stories_lists_published_approved_stories_for_others(): void
    {
        $this->fakeStorage();
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();

        $published = Story::factory()->for($owner)->published()->approved()->create();
        // A draft must not leak to another viewer.
        Story::factory()->for($owner)->create(['status' => 'draft']);

        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}/stories")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $published->id);
    }

    public function test_profile_stories_are_scoped_by_authorship_identity_not_involvement(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $otherAuthor = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create();

        $authoredAsCharacter = Story::factory()->for($otherAuthor)->create();
        $authoredAsCharacter->authors()->create([
            'user_id' => $owner->id,
            'character_id' => $character->id,
            'role' => StoryAuthor::ROLE_CO_AUTHOR,
            'status' => StoryAuthor::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        $involvementOnly = Story::factory()->for($otherAuthor)->create();
        $involvementOnly->involvements()->create([
            'involvable_type' => 'character',
            'involvable_id' => $character->id,
        ]);

        $mainIdentityStory = Story::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->getJson("/api/users/{$owner->id}/stories?character_id={$character->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $authoredAsCharacter->id);

        $this->actingAs($owner)
            ->getJson("/api/users/{$owner->id}/stories")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mainIdentityStory->id);
    }

    public function test_content_counts_match_listing_gating(): void
    {
        $this->fakeStorage();
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();

        Media::factory()->for($owner)->approved()->create();
        Media::factory()->for($owner)->approved()->audience(Audience::Followers)->create();

        // A non-follower counts only the public item.
        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}/content-counts")
            ->assertOk()
            ->assertJsonPath('data.media', 1)
            ->assertJsonPath('data.posts', 0)
            ->assertJsonPath('data.stories', 0)
            ->assertJsonPath('data.favorites', 0);

        // The owner counts both of their own items.
        $this->actingAs($owner)->getJson("/api/users/{$owner->id}/content-counts")
            ->assertOk()
            ->assertJsonPath('data.media', 2);
    }

    public function test_content_counts_are_scoped_to_the_selected_identity(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create();

        Media::factory()->for($owner)->approved()->create();
        Media::factory()->for($owner)->approved()->create(['character_id' => $character->id]);

        $this->actingAs($owner)->getJson("/api/users/{$owner->id}/content-counts")
            ->assertOk()->assertJsonPath('data.media', 1);

        $this->actingAs($owner)->getJson("/api/users/{$owner->id}/content-counts?character_id={$character->id}")
            ->assertOk()->assertJsonPath('data.media', 1)->assertJsonPath('data.favorites', 0);
    }
}
