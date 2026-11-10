<?php

namespace Tests\Feature\Location;

use App\Enums\Audience;
use App\Enums\LocationAudience;
use App\Models\Block;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Mute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationVisibilityTest extends TestCase
{
    use ImportsGeoNamesFixture;
    use RefreshDatabase;

    private const PORTLAND = 5746545;

    private const BEAVERTON = 5747882;   // ~10 km from Portland

    private const VANCOUVER = 5814616;   // ~13 km

    private const SALEM = 5735238;       // ~70 km

    private const PORTLAND_ME = 4975802; // ~4,000 km

    protected function setUp(): void
    {
        parent::setUp();
        $this->importGeoNamesFixture();
        // The first account becomes an administrator, who bypasses profile and
        // block gates; every viewer below must be an ordinary member.
        User::factory()->approved()->create();
    }

    public function test_profile_shows_city_country_or_nothing_by_each_audience(): void
    {
        $owner = $this->located(self::PORTLAND, LocationAudience::Everyone, LocationAudience::Mutuals);
        $mutual = User::factory()->approved()->create();
        $follower = User::factory()->approved()->create();
        $stranger = User::factory()->approved()->create();
        $this->follow($mutual, $owner);
        $this->follow($owner, $mutual);
        $this->follow($follower, $owner);

        $this->actingAs($mutual)->getJson("/api/users/{$owner->id}")
            ->assertJsonPath('data.location.level', 'city')
            ->assertJsonPath('data.location.label', 'Portland, Oregon, United States');

        $country = $this->actingAs($follower)->getJson("/api/users/{$owner->id}")
            ->assertJsonPath('data.location.level', 'country')
            ->assertJsonPath('data.location.label', 'United States')
            ->assertJsonPath('data.location.city', null);
        $this->assertStringNotContainsString('Portland', (string) $country->getContent());
        $this->assertStringNotContainsString('Oregon', (string) $country->getContent());

        $this->actingAs($stranger)->getJson("/api/users/{$owner->id}")->assertJsonPath('data.location.level', 'country');

        $owner->forceFill(['location_country_audience' => 'followers', 'location_city_audience' => 'followers'])->save();
        $this->actingAs($stranger)->getJson("/api/users/{$owner->id}")->assertJsonPath('data.location', null);
        $this->actingAs($follower)->getJson("/api/users/{$owner->id}")->assertJsonPath('data.location.level', 'city');

        $owner->forceFill(['location_country_audience' => 'nobody', 'location_city_audience' => 'nobody'])->save();
        $this->actingAs($mutual)->getJson("/api/users/{$owner->id}")->assertJsonPath('data.location', null);
    }

    public function test_one_way_follow_from_owner_does_not_count_as_follower_or_mutual(): void
    {
        $owner = $this->located(self::PORTLAND, LocationAudience::Followers, LocationAudience::Mutuals);
        $viewer = User::factory()->approved()->create();
        $this->follow($owner, $viewer);

        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}")->assertJsonPath('data.location', null);
    }

    public function test_restricted_profile_never_shows_location(): void
    {
        $owner = $this->located(self::PORTLAND, LocationAudience::Everyone, LocationAudience::Everyone);
        $owner->forceFill(['profile_audience' => Audience::Followers])->save();
        $viewer = User::factory()->approved()->create();

        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}")
            ->assertJsonPath('data.restricted', true)
            ->assertJsonPath('data.location', null);
    }

    public function test_owner_sees_own_city_and_editable_settings_on_me(): void
    {
        $owner = $this->located(self::PORTLAND, LocationAudience::Nobody, LocationAudience::Nobody);

        $this->actingAs($owner)->get('/me')
            ->assertOk()
            ->assertSee('Portland, Oregon, United States', false);
    }

    public function test_nearby_lists_only_people_whose_city_the_viewer_may_see(): void
    {
        $viewer = $this->located(self::PORTLAND, LocationAudience::Nobody, LocationAudience::Nobody);
        $sameCity = $this->located(self::PORTLAND, LocationAudience::Everyone, LocationAudience::Everyone, 'Same City');
        $beaverton = $this->located(self::BEAVERTON, LocationAudience::Everyone, LocationAudience::Everyone, 'Beaverton Person');
        $salem = $this->located(self::SALEM, LocationAudience::Everyone, LocationAudience::Everyone, 'Salem Person');
        $countryOnly = $this->located(self::VANCOUVER, LocationAudience::Everyone, LocationAudience::Nobody, 'Country Only');
        $followersOnly = $this->located(self::VANCOUVER, LocationAudience::Followers, LocationAudience::Followers, 'Followers Only');
        $far = $this->located(self::PORTLAND_ME, LocationAudience::Everyone, LocationAudience::Everyone, 'Far Away');
        $blocked = $this->located(self::PORTLAND, LocationAudience::Everyone, LocationAudience::Everyone, 'Blocked');
        $muted = $this->located(self::PORTLAND, LocationAudience::Everyone, LocationAudience::Everyone, 'Muted');
        $private = $this->located(self::PORTLAND, LocationAudience::Everyone, LocationAudience::Everyone, 'Private Profile');
        $private->forceFill(['profile_audience' => Audience::Mutuals])->save();
        $deactivated = $this->located(self::PORTLAND, LocationAudience::Everyone, LocationAudience::Everyone, 'Gone');
        $deactivated->forceFill(['deactivated_at' => now()])->save();
        Block::query()->create(['blocker_id' => $blocked->id, 'blocked_user_id' => $viewer->id]);
        Mute::query()->create(['user_id' => $viewer->id, 'muted_user_id' => $muted->id]);

        $response = $this->actingAs($viewer)->getJson('/api/location/nearby')
            ->assertOk()
            ->assertJsonPath('data.has_location', true)
            ->assertJsonPath('data.radius_km', 50);
        $this->assertSame(['Same City', 'Beaverton Person'], array_column($response->json('data.people'), 'display_name'));
        $response->assertJsonPath('data.people.0.same_city', true)
            ->assertJsonPath('data.people.0.within_km', null)
            ->assertJsonPath('data.people.1.within_km', 25)
            ->assertJsonPath('data.people.1.location.label', 'Beaverton, Oregon, United States');
        $this->assertStringNotContainsString('lat', (string) json_encode($response->json('data.people')));

        $wide = $this->getJson('/api/location/nearby?radius=100');
        $this->assertSame(['Same City', 'Beaverton Person', 'Salem Person'], array_column($wide->json('data.people'), 'display_name'));
        $wide->assertJsonPath('data.people.2.within_km', 100);

        $this->follow($viewer, $followersOnly);
        $this->assertContains('Followers Only', array_column($this->getJson('/api/location/nearby')->json('data.people'), 'display_name'));

        $this->getJson('/api/location/nearby?radius=7')->assertUnprocessable();
        $this->assertNotContains($countryOnly->id, array_column($wide->json('data.people'), 'id'));
        $this->assertNotContains($far->id, array_column($wide->json('data.people'), 'id'));
    }

    public function test_nearby_needs_the_viewers_own_city(): void
    {
        $viewer = User::factory()->approved()->create();
        $this->located(self::PORTLAND, LocationAudience::Everyone, LocationAudience::Everyone);

        $this->actingAs($viewer)->getJson('/api/location/nearby')
            ->assertOk()
            ->assertJsonPath('data.has_location', false)
            ->assertJsonCount(0, 'data.people');
    }

    public function test_personas_carry_no_location(): void
    {
        $owner = $this->located(self::PORTLAND, LocationAudience::Everyone, LocationAudience::Everyone);
        $separate = Character::factory()->for($owner)->audience(Audience::Everyone)->create(['is_linked' => false, 'discoverable' => true]);
        $viewer = $this->located(self::PORTLAND, LocationAudience::Nobody, LocationAudience::Nobody);

        $this->actingAs($owner)->patchJson("/api/characters/{$separate->id}", ['place_id' => self::SALEM]);
        $this->assertArrayNotHasKey('place_id', $separate->fresh()->getAttributes());

        $persona = $this->actingAs($viewer)->get("/c/{$separate->ulid}")->assertOk();
        $this->assertStringNotContainsString('Portland', (string) $persona->getContent());

        $nearby = $this->getJson('/api/location/nearby');
        $this->assertSame([$owner->id], array_column($nearby->json('data.people'), 'id'));
        $this->assertStringNotContainsString($separate->display_name, (string) $nearby->getContent());
    }

    private function located(int $placeId, LocationAudience $country, LocationAudience $city, ?string $name = null): User
    {
        $user = User::factory()->approved()->create(array_filter(['display_name' => $name]));
        $user->forceFill([
            'place_id' => $placeId,
            'location_country_audience' => $country,
            'location_city_audience' => $city,
        ])->save();

        return $user;
    }

    private function follow(User $follower, User $followee): void
    {
        FollowRequest::query()->create([
            'requester_id' => $follower->id,
            'recipient_id' => $followee->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
    }
}
