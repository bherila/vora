<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentitySessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_switch_to_an_owned_persona_and_back_to_their_profile(): void
    {
        $user = User::factory()->approved()->create();
        $persona = Character::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/identity', ['character_id' => $persona->id])
            ->assertOk()
            ->assertJsonPath('data.active_identity_id', $persona->id);
        $this->assertSame($persona->id, session('active_character_id'));

        $this->postJson('/api/identity', ['character_id' => null])
            ->assertOk()
            ->assertJsonPath('data.active_identity_id', null);
        $this->assertFalse(session()->has('active_character_id'));
    }

    public function test_switching_to_another_users_or_deleted_persona_is_a_generic_not_found(): void
    {
        $user = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $foreign = Character::factory()->for($other)->create();
        $deleted = Character::factory()->for($user)->create();
        $deleted->delete();

        foreach ([$foreign->id, $deleted->id, PHP_INT_MAX] as $characterId) {
            $this->actingAs($user)
                ->postJson('/api/identity', ['character_id' => $characterId])
                ->assertNotFound()
                ->assertJsonPath('message', 'Not found.');
        }

        $this->assertFalse(session()->has('active_character_id'));
    }

    public function test_navbar_hydrates_owned_identities_and_the_active_identity_without_ajax(): void
    {
        $user = User::factory()->approved()->create(['display_name' => 'Human Name']);
        $persona = Character::factory()->for($user)->create(['display_name' => 'Persona Name']);

        $payload = $this->initialData(
            $this->actingAs($user)
                ->withSession(['active_character_id' => $persona->id])
                ->get('/feed')
                ->assertOk()
                ->getContent(),
        );

        $this->assertSame($persona->id, $payload['navbar']['activeIdentityId']);
        $this->assertSame([
            [
                'id' => null,
                'displayName' => 'Human Name',
                'avatarUrl' => null,
            ],
            [
                'id' => $persona->id,
                'displayName' => 'Persona Name',
                'avatarUrl' => null,
            ],
        ], $payload['navbar']['identities']);
    }

    public function test_persona_free_user_gets_no_switcher_or_creating_as_copy(): void
    {
        $user = User::factory()->approved()->create();

        $response = $this->actingAs($user)->get('/feed')->assertOk();
        $payload = $this->initialData($response->getContent());

        $this->assertSame([], $payload['navbar']['identities']);
        $this->assertNull($payload['navbar']['activeIdentityId']);
        $response->assertSee('site-header border-b border-gray-200 dark:border-[#3E3E3A] h-14', false);
    }

    public function test_stale_active_identity_is_cleared_during_hydration(): void
    {
        $user = User::factory()->approved()->create();

        $payload = $this->initialData(
            $this->actingAs($user)
                ->withSession(['active_character_id' => PHP_INT_MAX])
                ->get('/feed')
                ->assertOk()
                ->getContent(),
        );

        $this->assertNull($payload['navbar']['activeIdentityId']);
        $this->assertFalse(session()->has('active_character_id'));
    }

    public function test_new_story_defaults_its_owner_authorship_to_the_active_identity(): void
    {
        $user = User::factory()->approved()->create();
        $persona = Character::factory()->for($user)->create();

        $this->actingAs($user)
            ->withSession(['active_character_id' => $persona->id])
            ->postJson('/api/stories', [
                'title' => 'Identity default',
                'type' => 'long_form',
            ])
            ->assertCreated()
            ->assertJsonPath('data.authors.0.character_id', $persona->id);

        $story = Story::query()->firstOrFail();
        $this->assertDatabaseHas('story_authors', [
            'story_id' => $story->id,
            'user_id' => $user->id,
            'character_id' => $persona->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function initialData(string $html): array
    {
        preg_match('/<script id="initial-data"[^>]*>\s*(.*?)\s*<\/script>/s', $html, $matches);
        $this->assertArrayHasKey(1, $matches, 'initial-data script not found');

        /** @var array<string, mixed> $payload */
        $payload = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }
}
