<?php

namespace Tests\Feature\Favorites;

use App\Enums\Audience;
use App\Models\FollowRequest;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
