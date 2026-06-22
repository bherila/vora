<?php

namespace Tests\Feature\Profile;

use App\Models\Character;
use App\Models\Favorite;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The profile-as-container surfaces: /me renders the owner's own profile in
 * editable mode, and a visitor's profile payload carries the character strip
 * plus the viewer's own favorited state.
 */
class SelfProfileTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStorage(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
        });
    }

    public function test_me_hydrates_the_owner_profile_in_editable_mode(): void
    {
        $user = User::factory()->approved()->create();

        $response = $this->actingAs($user)->get('/me')->assertOk();
        // Hydrated JSON (not HTML-escaped) carries owner mode + the editable block.
        $response->assertSee('"is_self":true', false);
        $response->assertSee('profileEditable', false);
    }

    public function test_profile_payload_exposes_the_character_strip_and_viewer_favorite_state(): void
    {
        $this->fakeStorage();
        User::factory()->create(); // spacer so nobody under test is the admin (id 1)
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        Character::factory()->for($owner)->create(['display_name' => 'Aria']);

        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}")
            ->assertOk()
            ->assertJsonPath('data.is_self', false)
            ->assertJsonPath('data.characters.0.display_name', 'Aria')
            ->assertJsonPath('data.viewer_favorited', false);

        Favorite::query()->create([
            'user_id' => $viewer->id,
            'favoritable_type' => $owner->getMorphClass(),
            'favoritable_id' => $owner->id,
        ]);

        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}")
            ->assertOk()
            ->assertJsonPath('data.viewer_favorited', true);
    }
}
