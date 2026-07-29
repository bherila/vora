<?php

namespace Tests\Feature\Characters;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Interest;
use App\Models\InterestRating;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class CharacterTest extends TestCase
{
    use RefreshDatabase;

    private function follow(User $follower, User $followee): void
    {
        FollowRequest::query()->create([
            'requester_id' => $follower->id,
            'recipient_id' => $followee->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
    }

    private function fakeStorage(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedUploadUrl')->andReturn([
                'url' => 'https://r2.example/put',
                'headers' => ['Content-Type' => 'image/png'],
            ]);
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
            $mock->shouldReceive('fileExists')->andReturn(true);
            $mock->shouldReceive('getFileSize')->andReturn(2048);
            $mock->shouldReceive('deleteFile')->andReturn(true);
        });
    }

    public function test_characters_receive_a_unique_ulid_and_default_to_linked(): void
    {
        $owner = User::factory()->approved()->create();

        $character = Character::query()->create([
            'user_id' => $owner->id,
            'display_name' => 'Nova',
        ]);

        $this->assertTrue(Str::isUlid($character->ulid));
        $this->assertTrue($character->is_linked);

        $this->expectException(QueryException::class);
        Character::query()->create([
            'user_id' => $owner->id,
            'ulid' => $character->ulid,
            'display_name' => 'Duplicate',
        ]);
    }

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
                'discoverable' => false,
                'preferred_user_types' => ['human', 'furry'],
                'preferred_genders' => ['female', 'other'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'Nova')
            ->assertJsonPath('data.audience', Audience::Everyone->value)
            ->assertJsonPath('data.audience_user_ids', [])
            ->assertJsonPath('data.gender_other', 'nonbinary')
            ->assertJsonPath('data.user_type', 'furry')
            ->assertJsonPath('data.discoverable', false)
            ->assertJsonPath('data.inherit_interests', true)
            ->assertJsonPath('data.is_linked', true)
            ->assertJsonMissingPath('data.preferred_user_types')
            ->assertJsonMissingPath('data.preferred_genders')
            ->json('data.id');

        $createdCharacter = Character::query()->findOrFail($created);
        $this->assertNull($createdCharacter->preferred_user_types);
        $this->assertNull($createdCharacter->preferred_genders);

        $this->patchJson("/api/characters/{$created}", [
            'display_name' => 'Nova Prime',
            'description' => null,
            'gender' => 'female',
            'gender_other' => null,
            'user_type' => 'other',
            'user_type_other' => 'construct',
            'discoverable' => true,
            'preferred_user_types' => null,
            'preferred_genders' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Nova Prime')
            ->assertJsonPath('data.gender_other', null)
            ->assertJsonPath('data.user_type', 'other')
            ->assertJsonPath('data.user_type_other', 'construct')
            ->assertJsonPath('data.discoverable', true)
            ->assertJsonMissingPath('data.preferred_user_types')
            ->assertJsonPath('data.is_linked', true);
    }

    public function test_separate_persona_discovery_defaults_are_independent_from_owner_preferences(): void
    {
        $user = User::factory()->approved()->create([
            'preferred_user_types' => ['other'],
            'preferred_genders' => ['other'],
        ]);

        $created = $this->actingAs($user)
            ->postJson('/api/characters', [
                'display_name' => 'Independent',
                'is_linked' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.discoverable', true)
            ->assertJsonMissingPath('data.preferred_user_types')
            ->assertJsonMissingPath('data.preferred_genders')
            ->json('data.id');

        $character = Character::query()->findOrFail($created);
        $this->assertFalse($character->is_linked);
        $this->assertTrue($character->discoverable);
        $this->assertNull($character->preferred_user_types);
        $this->assertNull($character->preferred_genders);
    }

    public function test_partial_update_preserves_every_omitted_persona_field_and_media_privacy(): void
    {
        $user = User::factory()->approved()->create();
        $allowed = User::factory()->approved()->create();
        $character = Character::factory()->for($user)->create([
            'description' => 'Keep this description.',
            'gender' => 'other',
            'gender_other' => 'agender',
            'user_type' => 'other',
            'user_type_other' => 'robot',
            'audience' => Audience::SpecificPeople,
            'discoverable' => false,
            'is_linked' => false,
        ]);
        $character->syncAudienceMembers([$allowed->id]);
        $media = Media::factory()->for($user)->approved()->create([
            'character_id' => $character->id,
            'audience' => Audience::SpecificPeople,
            'discoverable' => false,
        ]);
        $media->syncAudienceMembers([$allowed->id]);

        $this->actingAs($user)
            ->patchJson("/api/characters/{$character->id}", [
                'display_name' => 'Still link-only',
            ])
            ->assertOk()
            ->assertJsonPath('data.description', 'Keep this description.')
            ->assertJsonPath('data.gender', 'other')
            ->assertJsonPath('data.gender_other', 'agender')
            ->assertJsonPath('data.user_type', 'other')
            ->assertJsonPath('data.user_type_other', 'robot')
            ->assertJsonPath('data.discoverable', false)
            ->assertJsonPath('data.is_linked', false)
            ->assertJsonPath('data.audience', Audience::SpecificPeople->value)
            ->assertJsonPath('data.audience_user_ids', [$allowed->id]);

        $character->refresh();
        $media->refresh();
        $this->assertSame('Keep this description.', $character->description);
        $this->assertSame('other', $character->gender);
        $this->assertSame('agender', $character->gender_other);
        $this->assertSame('other', $character->user_type);
        $this->assertSame('robot', $character->user_type_other);
        $this->assertFalse($character->discoverable);
        $this->assertFalse($character->is_linked);
        $this->assertSame(Audience::SpecificPeople, $character->audience);
        $this->assertSame([$allowed->id], $character->audienceMembers()->pluck('user_id')->map('intval')->all());
        $this->assertFalse($media->discoverable);
        $this->assertSame(Audience::SpecificPeople, $media->audience);
        $this->assertSame([$allowed->id], $media->audienceMembers()->pluck('user_id')->map('intval')->all());
    }

    public function test_specific_persona_allowlist_can_change_without_repeating_the_unchanged_audience(): void
    {
        $user = User::factory()->approved()->create();
        $allowed = User::factory()->approved()->create();
        $notMutual = User::factory()->approved()->create();
        $character = Character::factory()->for($user)->create([
            'audience' => Audience::SpecificPeople,
        ]);
        $character->syncAudienceMembers([$allowed->id]);
        $media = Media::factory()->for($user)->approved()->create([
            'character_id' => $character->id,
            'audience' => Audience::SpecificPeople,
        ]);
        $media->syncAudienceMembers([$allowed->id]);

        $this->actingAs($user)
            ->patchJson("/api/characters/{$character->id}", [
                'display_name' => $character->display_name,
                'audience_user_ids' => [$notMutual->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('audience_user_ids.0');

        $this->patchJson("/api/characters/{$character->id}", [
            'display_name' => $character->display_name,
            'audience_user_ids' => [],
        ])
            ->assertOk()
            ->assertJsonPath('data.audience', Audience::SpecificPeople->value)
            ->assertJsonPath('data.audience_user_ids', []);

        $this->assertSame([], $character->audienceMembers()->pluck('user_id')->all());
        $this->assertSame([], $media->audienceMembers()->pluck('user_id')->all());
    }

    public function test_owner_can_switch_a_persona_between_linked_and_separate(): void
    {
        $user = User::factory()->approved()->create();
        $character = Character::factory()->for($user)->create(['is_linked' => true]);

        $this->actingAs($user)
            ->patchJson("/api/characters/{$character->id}", [
                'display_name' => $character->display_name,
                'is_linked' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_linked', false);
        $this->assertFalse($character->refresh()->is_linked);

        // Omitting the field leaves the choice untouched.
        $this->patchJson("/api/characters/{$character->id}", [
            'display_name' => $character->display_name,
        ])->assertOk()->assertJsonPath('data.is_linked', false);
        $this->assertFalse($character->refresh()->is_linked);
    }

    public function test_specific_character_with_empty_allowlist_is_owner_only(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->audience(Audience::SpecificPeople)->create();

        $this->assertTrue($character->isViewableBy($owner));
        $this->assertFalse($character->isViewableBy($viewer));
    }

    public function test_character_specific_access_requires_mutual_followers(): void
    {
        $owner = User::factory()->approved()->create();
        $mutual = User::factory()->approved()->create();
        $oneWay = User::factory()->approved()->create();
        $this->follow($mutual, $owner);
        $this->follow($owner, $mutual);
        $this->follow($oneWay, $owner);

        $this->actingAs($owner)->postJson('/api/characters', [
            'display_name' => 'Nova',
            'audience' => Audience::SpecificPeople->value,
            'audience_user_ids' => [$oneWay->id],
        ])->assertStatus(422)->assertJsonValidationErrors('audience_user_ids.0');

        $this->postJson('/api/characters', [
            'display_name' => 'Nova',
            'audience' => Audience::SpecificPeople->value,
            'audience_user_ids' => [$mutual->id],
        ])->assertCreated()
            ->assertJsonPath('data.audience', Audience::SpecificPeople->value)
            ->assertJsonPath('data.audience_user_ids', [$mutual->id]);
    }

    public function test_mutual_relationship_filter_lists_only_mutual_followers(): void
    {
        $owner = User::factory()->approved()->create();
        $mutual = User::factory()->approved()->create(['display_name' => 'Mutual']);
        $oneWay = User::factory()->approved()->create(['display_name' => 'One Way']);
        $unrelated = User::factory()->approved()->create(['display_name' => 'Unrelated']);
        $this->follow($mutual, $owner);
        $this->follow($owner, $mutual);
        $this->follow($oneWay, $owner);

        $ids = collect($this->actingAs($owner)->getJson('/api/users?relationship=mutuals')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($mutual->id, $ids);
        $this->assertNotContains($oneWay->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    public function test_character_media_inherits_character_specific_access(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $mutual = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $this->follow($mutual, $owner);
        $this->follow($owner, $mutual);
        $character = Character::factory()->for($owner)->audience(Audience::SpecificPeople)->create();
        $character->syncAudienceMembers([$mutual->id]);

        $response = $this->actingAs($owner)->postJson('/api/media', [
            'type' => 'photo',
            'filename' => 'nova.jpg',
            'content_type' => 'image/jpeg',
            'character_id' => $character->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.character_id', $character->id)
            ->assertJsonPath('data.audience', Audience::SpecificPeople->value)
            ->assertJsonPath('data.character.display_name', $character->display_name);

        $media = Media::query()->firstOrFail();
        $this->assertSame($character->id, $media->character_id);
        $this->assertSame(Audience::SpecificPeople, $media->audience);
        $this->assertSame([$mutual->id], $media->audienceMembers()->pluck('user_id')->map('intval')->all());
        $this->assertTrue($media->isViewableBy($mutual));
        $this->assertFalse($media->isViewableBy($other));
    }

    public function test_updating_character_privacy_propagates_to_associated_media(): void
    {
        $owner = User::factory()->approved()->create();
        $mutual = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $this->follow($mutual, $owner);
        $this->follow($owner, $mutual);

        $character = Character::factory()->for($owner)->create(['display_name' => 'Nova']);
        $media = Media::factory()->for($owner)->create([
            'character_id' => $character->id,
            'audience' => Audience::Everyone,
        ]);

        $this->actingAs($owner)->patchJson("/api/characters/{$character->id}", [
            'display_name' => 'Nova',
            'audience' => Audience::SpecificPeople->value,
            'audience_user_ids' => [$mutual->id],
        ])->assertOk();

        $fresh = $media->fresh();
        $this->assertSame(Audience::SpecificPeople, $fresh->audience);
        $this->assertSame([$mutual->id], $fresh->audienceMembers()->pluck('user_id')->map('intval')->all());
        $this->assertTrue($fresh->isViewableBy($mutual));
        $this->assertFalse($fresh->isViewableBy($other));
    }

    public function test_standalone_media_specific_access_requires_mutual_followers(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();

        $this->actingAs($owner)->postJson('/api/media', [
            'type' => 'photo',
            'filename' => 'nova.jpg',
            'content_type' => 'image/jpeg',
            'audience' => Audience::SpecificPeople->value,
            'audience_user_ids' => [$viewer->id],
        ])->assertStatus(422)->assertJsonValidationErrors('audience_user_ids.0');
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

    public function test_linked_character_copies_owner_ratings_when_switching_to_specific(): void
    {
        $user = User::factory()->approved()->create();
        $animals = Interest::query()->create(['name' => 'Animals']);
        $art = Interest::query()->create(['name' => 'Art']);
        $character = Character::factory()->for($user)->create([
            'is_linked' => true,
            'inherit_interests' => true,
        ]);

        InterestRating::query()->create([
            'user_id' => $user->id,
            'character_id' => null,
            'interest_id' => $animals->id,
            'level' => 5,
        ]);
        InterestRating::query()->create([
            'user_id' => $user->id,
            'character_id' => null,
            'interest_id' => $art->id,
            'level' => -3,
        ]);

        $this->actingAs($user)
            ->postJson('/api/interests/inherit', ['character_id' => $character->id, 'inherit' => false])
            ->assertOk()
            ->assertJsonPath('data.inherit_interests', false);

        $this->assertDatabaseHas('interest_ratings', [
            'user_id' => $user->id,
            'character_id' => $character->id,
            'interest_id' => $animals->id,
            'level' => 5,
        ]);
        $this->assertDatabaseHas('interest_ratings', [
            'user_id' => $user->id,
            'character_id' => $character->id,
            'interest_id' => $art->id,
            'level' => -3,
        ]);
    }

    public function test_separate_character_starts_with_no_owner_interest_fingerprint(): void
    {
        $user = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Rare Interest']);
        $character = Character::factory()->for($user)->create([
            'is_linked' => false,
            'inherit_interests' => true,
        ]);

        $this->assertFalse($character->inherit_interests);

        InterestRating::query()->create([
            'user_id' => $user->id,
            'character_id' => null,
            'interest_id' => $interest->id,
            'level' => 7,
        ]);

        $this->actingAs($user)
            ->postJson('/api/interests/inherit', ['character_id' => $character->id, 'inherit' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('inherit');

        $this->assertDatabaseMissing('interest_ratings', [
            'character_id' => $character->id,
        ]);
    }

    public function test_switching_a_linked_character_to_separate_preserves_explicit_persona_ratings(): void
    {
        $user = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Identifying Interest']);
        $character = Character::factory()->for($user)->create([
            'is_linked' => true,
            'inherit_interests' => false,
        ]);
        InterestRating::query()->create([
            'user_id' => $user->id,
            'character_id' => $character->id,
            'interest_id' => $interest->id,
            'level' => 7,
        ]);

        $character->update(['is_linked' => false]);

        $this->assertFalse($character->refresh()->inherit_interests);
        $this->assertDatabaseHas('interest_ratings', [
            'character_id' => $character->id,
            'interest_id' => $interest->id,
            'level' => 7,
        ]);
    }

    public function test_first_explicit_linked_character_rating_seeds_then_overrides_owner_ratings(): void
    {
        $user = User::factory()->approved()->create();
        $animals = Interest::query()->create(['name' => 'Animals']);
        $art = Interest::query()->create(['name' => 'Art']);
        $character = Character::factory()->for($user)->create([
            'is_linked' => true,
            'inherit_interests' => true,
        ]);
        InterestRating::query()->create([
            'user_id' => $user->id,
            'character_id' => null,
            'interest_id' => $animals->id,
            'level' => 5,
        ]);
        InterestRating::query()->create([
            'user_id' => $user->id,
            'character_id' => null,
            'interest_id' => $art->id,
            'level' => 3,
        ]);

        $this->actingAs($user)
            ->postJson('/api/interests/ratings', [
                'character_id' => $character->id,
                'ratings' => [['interest_id' => $animals->id, 'level' => -2]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('interest_ratings', [
            'character_id' => $character->id,
            'interest_id' => $animals->id,
            'level' => -2,
        ]);
        $this->assertDatabaseHas('interest_ratings', [
            'character_id' => $character->id,
            'interest_id' => $art->id,
            'level' => 3,
        ]);
    }

    public function test_cross_user_character_rating_is_hidden_and_does_not_change_victim_ratings(): void
    {
        $attacker = User::factory()->approved()->create();
        $victim = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Photography']);
        $character = Character::query()->create([
            'user_id' => $victim->id,
            'display_name' => 'Hidden Persona',
            'inherit_interests' => false,
        ]);
        InterestRating::query()->create([
            'user_id' => $victim->id,
            'character_id' => $character->id,
            'interest_id' => $interest->id,
            'level' => 4,
        ]);

        $this->actingAs($attacker)
            ->postJson('/api/interests/ratings', [
                'character_id' => $character->id,
                'ratings' => [['interest_id' => $interest->id, 'level' => 9]],
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('interest_ratings', [
            'user_id' => $victim->id,
            'character_id' => $character->id,
            'interest_id' => $interest->id,
            'level' => 4,
        ]);
        $this->assertDatabaseMissing('interest_ratings', [
            'user_id' => $attacker->id,
            'character_id' => $character->id,
        ]);
    }

    public function test_cross_user_character_inheritance_is_hidden_and_does_not_change_victim_ratings(): void
    {
        $attacker = User::factory()->approved()->create();
        $victim = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Photography']);
        $character = Character::query()->create([
            'user_id' => $victim->id,
            'display_name' => 'Hidden Persona',
            'inherit_interests' => false,
        ]);
        InterestRating::query()->create([
            'user_id' => $victim->id,
            'character_id' => $character->id,
            'interest_id' => $interest->id,
            'level' => 4,
        ]);

        $this->actingAs($attacker)
            ->postJson('/api/interests/inherit', ['character_id' => $character->id, 'inherit' => 'maybe'])
            ->assertNotFound();

        $this->assertFalse($character->refresh()->inherit_interests);
        $this->assertDatabaseHas('interest_ratings', [
            'user_id' => $victim->id,
            'character_id' => $character->id,
            'interest_id' => $interest->id,
            'level' => 4,
        ]);
    }

    public function test_missing_numeric_character_ids_are_hidden_as_not_found(): void
    {
        $user = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Cooking']);

        $this->actingAs($user)
            ->postJson('/api/interests/ratings', [
                'character_id' => PHP_INT_MAX,
                'ratings' => [['interest_id' => $interest->id, 'level' => 1]],
            ])
            ->assertNotFound();

        $this->postJson('/api/interests/inherit', [
            'character_id' => PHP_INT_MAX,
            'inherit' => false,
        ])->assertNotFound();
    }

    public function test_soft_deleted_owned_character_ids_are_hidden_as_not_found(): void
    {
        $user = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Cooking']);
        $character = Character::factory()->for($user)->create();
        $character->delete();

        $this->actingAs($user)
            ->postJson('/api/interests/ratings', [
                'character_id' => $character->id,
                'ratings' => [['interest_id' => $interest->id, 'level' => 1]],
            ])
            ->assertNotFound();

        $this->postJson('/api/interests/inherit', [
            'character_id' => $character->id,
            'inherit' => false,
        ])->assertNotFound();
    }

    public function test_malformed_character_ids_return_validation_errors(): void
    {
        $user = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Cooking']);

        $this->actingAs($user)
            ->postJson('/api/interests/ratings', [
                'character_id' => ['nope'],
                'ratings' => [['interest_id' => $interest->id, 'level' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('character_id');

        $this->postJson('/api/interests/inherit', [
            'character_id' => ['nope'],
            'inherit' => false,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('character_id');

        // The separate GET listing path still conceals malformed lookup input.
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

    public function test_character_avatar_rejects_disallowed_image_types(): void
    {
        $user = User::factory()->approved()->create();
        $character = Character::query()->create(['user_id' => $user->id, 'display_name' => 'Nova']);

        // Active/odd image formats outside the photo allowlist (e.g. SVG) must be
        // rejected at presign, matching the user profile-picture path — a bare
        // starts_with:image/ check would have let these through.
        foreach (['image/svg+xml', 'image/bmp'] as $contentType) {
            $this->actingAs($user)
                ->postJson("/api/characters/{$character->id}/profile-picture", [
                    'filename' => 'avatar',
                    'content_type' => $contentType,
                    'size' => 2048,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('content_type');
        }
    }

    public function test_character_avatar_accepts_allowed_image_type(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();
        $character = Character::query()->create(['user_id' => $user->id, 'display_name' => 'Nova']);

        $this->actingAs($user)
            ->postJson("/api/characters/{$character->id}/profile-picture", [
                'filename' => 'avatar.png',
                'content_type' => 'image/png',
                'size' => 2048,
            ])
            ->assertCreated();
    }

    public function test_character_avatar_rejects_non_string_content_type_with_422_not_500(): void
    {
        $user = User::factory()->approved()->create();
        $character = Character::query()->create(['user_id' => $user->id, 'display_name' => 'Nova']);

        // A malformed array content_type must validate as 422, not blow up the MIME
        // allowlist closure with an "Array to string conversion" 500.
        $this->actingAs($user)
            ->postJson("/api/characters/{$character->id}/profile-picture", [
                'filename' => 'avatar',
                'content_type' => ['image/png'],
                'size' => 2048,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('content_type');
    }

    public function test_characters_page_redirects_to_profile(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->get('/characters')->assertRedirect(route('me'));
    }

    public function test_owner_can_open_the_dedicated_persona_create_and_edit_pages(): void
    {
        $owner = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create([
            'display_name' => 'Nova',
            'description' => 'A test persona.',
            'discoverable' => false,
            'is_linked' => false,
        ]);

        $createPayload = $this->initialData(
            $this->actingAs($owner)->get('/personas/new')->assertOk()->getContent()
        );
        $this->assertNull($createPayload['personaEditor']['character']);

        $editPayload = $this->initialData(
            $this->get("/c/{$character->ulid}/edit")->assertOk()->getContent()
        );
        $editable = $editPayload['personaEditor']['character'];
        $this->assertSame($character->id, $editable['id']);
        $this->assertSame($character->ulid, $editable['ulid']);
        $this->assertSame('Nova', $editable['display_name']);
        $this->assertFalse($editable['discoverable']);
        $this->assertFalse($editable['is_linked']);
        $this->assertArrayHasKey('inherit_interests', $editable);
        $this->assertArrayHasKey('audience_user_ids', $editable);
        $this->assertArrayNotHasKey('preferred_user_types', $editable);
        $this->assertArrayNotHasKey('preferred_genders', $editable);
    }

    public function test_persona_edit_page_hides_foreign_and_missing_ulids_identically(): void
    {
        $owner = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create();

        $foreign = $this->actingAs($other)
            ->get("/c/{$character->ulid}/edit")
            ->assertNotFound();
        $missing = $this->get('/c/01HZZZZZZZZZZZZZZZZZZZZZZZ/edit')
            ->assertNotFound();

        $this->assertSame($missing->getContent(), $foreign->getContent());
        $this->assertStringNotContainsString(Character::class, $foreign->getContent());
        $this->assertStringNotContainsString($character->ulid, $foreign->getContent());
    }

    public function test_profile_home_hydrates_full_character_records_for_owner(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();
        Character::query()->create(['user_id' => $user->id, 'display_name' => 'Nova', 'description' => 'A test persona']);

        $html = $this->actingAs($user)->get('/me')->assertOk()->getContent();
        preg_match('/<script id="initial-data"[^>]*>\s*(.*?)\s*<\/script>/s', (string) $html, $matches);
        $payload = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        $characters = $payload['profileCharacters'] ?? null;
        $this->assertIsArray($characters);
        $this->assertCount(1, $characters);
        $this->assertSame('Nova', $characters[0]['display_name']);
        $this->assertSame('A test persona', $characters[0]['description']);
        // Owner controls need the stable edit target, privacy, and inherit flag.
        $this->assertArrayHasKey('ulid', $characters[0]);
        $this->assertArrayHasKey('inherit_interests', $characters[0]);
        $this->assertArrayHasKey('audience_user_ids', $characters[0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function initialData(string $html): array
    {
        preg_match('/<script id="initial-data"[^>]*>\s*(.*?)\s*<\/script>/s', $html, $matches);

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }
}
