<?php

namespace Tests\Feature\Characters;

use App\Models\Character;
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
                'preferred_user_types' => ['human', 'furry'],
                'preferred_genders' => ['female', 'other'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'Nova')
            ->assertJsonPath('data.gender_other', 'nonbinary')
            ->json('data.id');

        $this->patchJson("/api/characters/{$created}", [
            'display_name' => 'Nova Prime',
            'description' => null,
            'gender' => 'female',
            'gender_other' => null,
            'preferred_user_types' => null,
            'preferred_genders' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Nova Prime')
            ->assertJsonPath('data.gender_other', null)
            ->assertJsonPath('data.preferred_user_types', []);
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
