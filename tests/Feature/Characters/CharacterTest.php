<?php

namespace Tests\Feature\Characters;

use App\Models\Character;
use App\Models\CharacterInterestRating;
use App\Models\Interest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterTest extends TestCase
{
    use RefreshDatabase;

    public function test_characters_are_optional_and_user_scoped(): void
    {
        $owner = User::factory()->approved()->create();
        $otherUser = User::factory()->approved()->create();
        Character::query()->create([
            'user_id' => $otherUser->id,
            'display_name' => 'Hidden Persona',
        ]);

        $this->actingAs($owner)
            ->getJson('/api/characters')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_user_can_create_and_update_character_profile(): void
    {
        $user = User::factory()->approved()->create();

        $created = $this->actingAs($user)
            ->postJson('/api/characters', [
                'display_name' => 'Nova',
                'description' => 'Space fox painter.',
                'gender' => 'other',
                'gender_other' => 'nonbinary',
                'user_type' => 'furry',
                'preferred_user_types' => ['human', 'furry'],
                'preferred_genders' => ['female', 'other'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'Nova')
            ->assertJsonPath('data.gender_other', 'nonbinary')
            ->assertJsonPath('data.user_type', 'furry')
            ->assertJsonPath('data.inherit_interests', true)
            ->json('data.id');

        $this->patchJson("/api/characters/{$created}", [
            'display_name' => 'Nova Prime',
            'description' => null,
            'gender' => 'female',
            'gender_other' => null,
            'user_type' => 'other',
            'user_type_other' => 'construct',
            'preferred_user_types' => null,
            'preferred_genders' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Nova Prime')
            ->assertJsonPath('data.gender_other', null)
            ->assertJsonPath('data.user_type', 'other')
            ->assertJsonPath('data.user_type_other', 'construct')
            ->assertJsonPath('data.preferred_user_types', []);
    }

    public function test_character_type_other_requires_detail(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)
            ->postJson('/api/characters', [
                'display_name' => 'Nameless',
                'user_type' => 'other',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_type_other');
    }

    public function test_rating_a_character_interest_overrides_inheritance(): void
    {
        $user = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Watercolor']);
        $character = Character::query()->create([
            'user_id' => $user->id,
            'display_name' => 'Nova',
        ]);

        $this->assertTrue($character->refresh()->inherit_interests);

        $this->actingAs($user)
            ->postJson("/api/characters/{$character->id}/interests/{$interest->id}/rate", ['level' => 7])
            ->assertOk()
            ->assertJsonPath('data.level', 7);

        $this->assertFalse($character->refresh()->inherit_interests);
        $this->assertDatabaseHas('character_interest_ratings', [
            'character_id' => $character->id,
            'interest_id' => $interest->id,
            'level' => 7,
        ]);

        $this->getJson("/api/characters/{$character->id}/interests")
            ->assertOk()
            ->assertJsonPath('inherit_interests', false)
            ->assertJsonPath('data.0.rating', 7);
    }

    public function test_switching_back_to_inherit_clears_character_overrides(): void
    {
        $user = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Sculpting']);
        $character = Character::query()->create([
            'user_id' => $user->id,
            'display_name' => 'Nova',
            'inherit_interests' => false,
        ]);
        CharacterInterestRating::query()->create([
            'character_id' => $character->id,
            'interest_id' => $interest->id,
            'level' => -4,
        ]);

        $this->actingAs($user)
            ->postJson("/api/characters/{$character->id}/interests/inherit", ['inherit' => true])
            ->assertOk()
            ->assertJsonPath('data.inherit_interests', true);

        $this->assertDatabaseMissing('character_interest_ratings', [
            'character_id' => $character->id,
        ]);
    }

    public function test_user_cannot_rate_another_users_character(): void
    {
        $owner = User::factory()->approved()->create();
        $otherUser = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Photography']);
        $character = Character::query()->create([
            'user_id' => $otherUser->id,
            'display_name' => 'Hidden Persona',
        ]);

        $this->actingAs($owner)
            ->postJson("/api/characters/{$character->id}/interests/{$interest->id}/rate", ['level' => 3])
            ->assertNotFound();

        $this->assertDatabaseMissing('character_interest_ratings', [
            'character_id' => $character->id,
        ]);
    }

    public function test_user_cannot_update_another_users_character(): void
    {
        $owner = User::factory()->approved()->create();
        $otherUser = User::factory()->approved()->create();
        $character = Character::query()->create([
            'user_id' => $otherUser->id,
            'display_name' => 'Hidden Persona',
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/characters/{$character->id}", [
                'display_name' => 'Stolen Persona',
            ])
            ->assertNotFound();
    }
}
