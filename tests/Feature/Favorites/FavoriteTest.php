<?php

namespace Tests\Feature\Favorites;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Media;
use App\Models\User;
use App\Notifications\ContentFavorited;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use Tests\TestCase;

class FavoriteTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStorage(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
        });
    }

    /** Make the given follower follow the given owner (accepted). */
    private function follow(User $follower, User $owner): void
    {
        FollowRequest::query()->create([
            'requester_id' => $follower->id,
            'recipient_id' => $owner->id,
            'status' => 'accepted',
        ]);
    }

    public function test_user_can_favorite_visible_media_and_see_it_in_their_list(): void
    {
        $this->fakeStorage();
        User::factory()->create(); // spacer so nobody under test is the admin (id 1)
        $alice = User::factory()->approved()->create();
        $bob = User::factory()->approved()->create();
        $media = Media::factory()->for($bob)->approved()->create(['title' => 'Sunset']);

        $this->actingAs($alice)->postJson('/api/favorites', ['type' => 'media', 'id' => $media->id])
            ->assertCreated()
            ->assertJsonPath('data.favorited', true)
            ->assertJsonPath('data.favorite.label', 'Sunset');

        $this->actingAs($alice)->getJson("/api/users/{$alice->id}/favorites")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $media->id);
    }

    public function test_favoriting_notifies_the_owner_once_and_never_on_self(): void
    {
        Notification::fake();
        $this->fakeStorage();
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $alice = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create(['title' => 'Pic']);

        // Saving your own item never notifies you.
        $this->actingAs($owner)->postJson('/api/favorites', ['type' => 'media', 'id' => $media->id])->assertCreated();
        Notification::assertNothingSentTo($owner);

        // Another user saving it notifies the owner exactly once, even on a repeat save.
        $this->actingAs($alice)->postJson('/api/favorites', ['type' => 'media', 'id' => $media->id])->assertCreated();
        $this->actingAs($alice)->postJson('/api/favorites', ['type' => 'media', 'id' => $media->id])->assertCreated();
        Notification::assertSentToTimes($owner, ContentFavorited::class, 1);
    }

    public function test_owner_can_silence_favorite_notifications(): void
    {
        Notification::fake();
        $this->fakeStorage();
        User::factory()->create();
        $owner = User::factory()->approved()->create(['notify_favorite' => false]);
        $alice = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create();

        $this->actingAs($alice)->postJson('/api/favorites', ['type' => 'media', 'id' => $media->id])->assertCreated();
        Notification::assertNothingSentTo($owner);
    }

    public function test_media_view_reports_an_aggregate_favorite_count(): void
    {
        $this->fakeStorage();
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $alice = User::factory()->approved()->create();
        $bob = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create(['title' => 'Popular']);

        $this->actingAs($alice)->postJson('/api/favorites', ['type' => 'media', 'id' => $media->id])->assertCreated();
        $this->actingAs($bob)->postJson('/api/favorites', ['type' => 'media', 'id' => $media->id])->assertCreated();

        // A third viewer who has not saved it sees the aggregate count, not their own state.
        $this->actingAs($owner)->getJson("/api/media/by-ulid/{$media->ulid}")
            ->assertOk()
            ->assertJsonPath('data.favorite_count', 2)
            ->assertJsonPath('data.favorited', false);
    }

    public function test_cannot_favorite_media_you_cannot_see(): void
    {
        $this->fakeStorage();
        User::factory()->create();
        $alice = User::factory()->approved()->create();
        $bob = User::factory()->approved()->create();
        $private = Media::factory()->for($bob)->approved()->audience(Audience::Followers)->create();

        $this->actingAs($alice)->postJson('/api/favorites', ['type' => 'media', 'id' => $private->id])
            ->assertForbidden();
    }

    public function test_favorites_list_hides_items_the_viewer_cannot_see(): void
    {
        $this->fakeStorage();
        User::factory()->create();
        $alice = User::factory()->approved()->create();
        $bob = User::factory()->approved()->create();

        $public = Media::factory()->for($bob)->approved()->create(['title' => 'Public']);
        $followersOnly = Media::factory()->for($bob)->approved()->audience(Audience::Followers)->create(['title' => 'Followers']);

        // Alice follows Bob, so she can see and favorite both.
        $this->follow($alice, $bob);
        $this->actingAs($alice)->postJson('/api/favorites', ['type' => 'media', 'id' => $public->id])->assertCreated();
        $this->actingAs($alice)->postJson('/api/favorites', ['type' => 'media', 'id' => $followersOnly->id])->assertCreated();

        // Alice sees both of her favorites.
        $this->actingAs($alice)->getJson("/api/users/{$alice->id}/favorites")
            ->assertOk()->assertJsonCount(2, 'data');

        // Carol can view Alice's (public) profile but does not follow Bob, so the
        // followers-only item drops out of Alice's favorites for her.
        $carol = User::factory()->approved()->create();
        $this->actingAs($carol)->getJson("/api/users/{$alice->id}/favorites")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $public->id);
    }

    public function test_favorites_require_profile_visibility(): void
    {
        $this->fakeStorage();
        User::factory()->create();
        $alice = User::factory()->approved()->create(['profile_audience' => Audience::Followers]);
        $carol = User::factory()->approved()->create();

        // Carol does not follow Alice, so she may not view Alice's profile at all.
        $this->actingAs($carol)->getJson("/api/users/{$alice->id}/favorites")
            ->assertForbidden();
    }

    public function test_unfavorite_removes_the_entry(): void
    {
        $this->fakeStorage();
        User::factory()->create();
        $alice = User::factory()->approved()->create();
        $bob = User::factory()->approved()->create();
        $media = Media::factory()->for($bob)->approved()->create();

        $this->actingAs($alice)->postJson('/api/favorites', ['type' => 'media', 'id' => $media->id])->assertCreated();
        $this->actingAs($alice)->deleteJson('/api/favorites', ['type' => 'media', 'id' => $media->id])
            ->assertOk()
            ->assertJsonPath('data.favorited', false);

        $this->actingAs($alice)->getJson("/api/users/{$alice->id}/favorites")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_user_can_favorite_a_profile(): void
    {
        $this->fakeStorage();
        User::factory()->create();
        $alice = User::factory()->approved()->create();
        $bob = User::factory()->approved()->create(['display_name' => 'Bob Vega']);

        $this->actingAs($alice)->postJson('/api/favorites', ['type' => 'user', 'id' => $bob->id])
            ->assertCreated()
            ->assertJsonPath('data.favorite.type', 'user')
            ->assertJsonPath('data.favorite.label', 'Bob Vega');

        $this->actingAs($alice)->getJson("/api/users/{$alice->id}/favorites")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'user');
    }

    public function test_allowlisted_viewer_can_favorite_a_specific_people_character(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $character = Character::factory()
            ->for($owner)
            ->audience(Audience::SpecificPeople)
            ->create(['display_name' => 'Allowlisted Persona']);
        $character->syncAudienceMembers([$viewer->id]);

        $this->assertTrue($character->isViewableBy($viewer));
        $this->assertFalse($character->isViewableBy($other));

        $this->actingAs($viewer)
            ->postJson('/api/favorites', ['type' => 'character', 'id' => $character->id])
            ->assertCreated()
            ->assertJsonPath('data.favorite.type', 'character');

        $this->actingAs($viewer)
            ->getJson("/api/users/{$viewer->id}/favorites")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $character->id)
            ->assertJsonPath('data.0.type', 'character');
    }

    public function test_characters_of_unapproved_or_inactive_owners_remain_hidden(): void
    {
        User::factory()->create();
        $viewer = User::factory()->approved()->create();
        $pendingOwner = User::factory()->pendingApproval()->create();
        $disabledOwner = User::factory()->approved()->disabled()->create();
        $pendingCharacter = Character::factory()->for($pendingOwner)->create();
        $disabledCharacter = Character::factory()->for($disabledOwner)->create();

        $this->assertFalse($pendingCharacter->isViewableBy($viewer));
        $this->assertFalse($disabledCharacter->isViewableBy($viewer));

        $this->actingAs($viewer)
            ->postJson('/api/favorites', ['type' => 'character', 'id' => $pendingCharacter->id])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->postJson('/api/favorites', ['type' => 'character', 'id' => $disabledCharacter->id])
            ->assertForbidden();
    }
}
