<?php

namespace Tests\Feature\Characters;

use App\Models\Character;
use App\Models\Interest;
use App\Models\InterestRating;
use App\Models\User;
use Illuminate\Database\QueryException;
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

    public function test_user_can_batch_rate_their_own_interests(): void
    {
        $user = User::factory()->approved()->create();
        $animals = Interest::query()->create(['name' => 'Animals']);
        $art = Interest::query()->create(['name' => 'Art']);

        $this->actingAs($user)
            ->postJson('/api/interests/ratings', [
                'character_id' => null,
                'ratings' => [
                    ['interest_id' => $animals->id, 'level' => 5],
                    ['interest_id' => $art->id, 'level' => -3],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('interest_ratings', [
            'user_id' => $user->id, 'character_id' => null, 'interest_id' => $animals->id, 'level' => 5,
        ]);
        $this->assertDatabaseHas('interest_ratings', [
            'user_id' => $user->id, 'character_id' => null, 'interest_id' => $art->id, 'level' => -3,
        ]);

        // A null level clears a rating.
        $this->postJson('/api/interests/ratings', [
            'ratings' => [['interest_id' => $animals->id, 'level' => null]],
        ])->assertOk();

        $this->assertDatabaseMissing('interest_ratings', [
            'user_id' => $user->id, 'character_id' => null, 'interest_id' => $animals->id,
        ]);
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
            ->postJson('/api/interests/ratings', [
                'character_id' => $character->id,
                'ratings' => [['interest_id' => $interest->id, 'level' => 7]],
            ])
            ->assertOk();

        $this->assertFalse($character->refresh()->inherit_interests);
        $this->assertDatabaseHas('interest_ratings', [
            'user_id' => $user->id,
            'character_id' => $character->id,
            'interest_id' => $interest->id,
            'level' => 7,
        ]);

        $this->getJson("/api/interests?character_id={$character->id}")
            ->assertOk()
            ->assertJsonPath('inherit_interests', false)
            ->assertJsonPath('data.0.rating', 7);

        // The override must not leak into the user's own profile ratings.
        $this->getJson('/api/interests')
            ->assertOk()
            ->assertJsonPath('data.0.rating', null);
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
        InterestRating::query()->create([
            'user_id' => $user->id,
            'character_id' => $character->id,
            'interest_id' => $interest->id,
            'level' => -4,
        ]);

        $this->actingAs($user)
            ->postJson('/api/interests/inherit', ['character_id' => $character->id, 'inherit' => true])
            ->assertOk()
            ->assertJsonPath('data.inherit_interests', true);

        $this->assertDatabaseMissing('interest_ratings', [
            'character_id' => $character->id,
        ]);
    }

    public function test_cross_user_character_rating_is_hidden_as_not_found(): void
    {
        $owner = User::factory()->approved()->create();
        $otherUser = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Photography']);
        $character = Character::query()->create([
            'user_id' => $otherUser->id,
            'display_name' => 'Hidden Persona',
        ]);

        // Even with an otherwise-invalid payload, ownership is enforced first so
        // the response is 404 (not a 422 that would leak the character's existence).
        $this->actingAs($owner)
            ->postJson('/api/interests/ratings', [
                'character_id' => $character->id,
                'ratings' => [['interest_id' => $interest->id, 'level' => 999]],
            ])
            ->assertNotFound();

        $this->actingAs($owner)
            ->postJson('/api/interests/inherit', ['character_id' => $character->id, 'inherit' => 'maybe'])
            ->assertNotFound();

        $this->assertDatabaseMissing('interest_ratings', [
            'character_id' => $character->id,
        ]);
    }

    public function test_malformed_character_id_does_not_error(): void
    {
        $user = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Cooking']);

        // A non-scalar character_id must be rejected as 404, not 500.
        $this->actingAs($user)
            ->postJson('/api/interests/ratings', [
                'character_id' => ['nope'],
                'ratings' => [['interest_id' => $interest->id, 'level' => 1]],
            ])
            ->assertNotFound();

        // The GET listing path guards the query value the same way.
        $this->getJson('/api/interests?character_id[]=1')->assertNotFound();
    }

    public function test_duplicate_profile_rating_is_prevented(): void
    {
        $user = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Gaming']);

        InterestRating::query()->create([
            'user_id' => $user->id, 'character_id' => null, 'interest_id' => $interest->id, 'level' => 2,
        ]);

        $this->expectException(QueryException::class);
        InterestRating::query()->create([
            'user_id' => $user->id, 'character_id' => null, 'interest_id' => $interest->id, 'level' => 5,
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
