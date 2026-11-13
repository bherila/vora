<?php

namespace Tests\Feature\Location;

use App\Enums\Audience;
use App\Enums\LocationAudience;
use App\Http\Controllers\Location\NearbyPeopleController;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Near me's bounded processing, and privacy regressions for the paths the
 * post-hoc review found correct (admins, view-as, persona follows, and
 * country-only payloads).
 */
class NearbyAndPrivacyTest extends TestCase
{
    use ImportsGeoNamesFixture;
    use RefreshDatabase;

    private const PORTLAND = 5746545;

    private const BEAVERTON = 5747882;

    private const VANCOUVER = 5814616;

    private const SALEM = 5735238;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importGeoNamesFixture();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_near_me_returns_the_nearest_permitted_people_across_chunks(): void
    {
        // Two candidates per pass, two results: the nearest people are
        // spread across passes and mixed with nearer ones who are not
        // permitted, so taking the first ones found would be wrong.
        $this->app->when(NearbyPeopleController::class)->needs('$chunkSize')->give(2);
        $this->app->when(NearbyPeopleController::class)->needs('$limit')->give(2);

        $viewer = $this->located(self::PORTLAND, LocationAudience::Nobody, LocationAudience::Nobody, 'Viewer');
        $this->located(self::SALEM, LocationAudience::Everyone, LocationAudience::Everyone, 'Salem Far');
        $this->located(self::PORTLAND, LocationAudience::Everyone, LocationAudience::Followers, 'Same City Hidden');
        $this->located(self::VANCOUVER, LocationAudience::Everyone, LocationAudience::Everyone, 'Vancouver');
        $this->located(self::BEAVERTON, LocationAudience::Everyone, LocationAudience::Mutuals, 'Beaverton Hidden');
        $this->located(self::PORTLAND, LocationAudience::Everyone, LocationAudience::Everyone, 'Same City');

        $people = $this->actingAs($viewer)->getJson('/api/location/nearby?radius=100')->assertOk()->json('data.people');

        $this->assertSame(['Same City', 'Vancouver'], array_column($people, 'display_name'));
    }

    public function test_country_only_payloads_carry_nothing_that_locates_the_city(): void
    {
        $owner = $this->located(self::PORTLAND, LocationAudience::Everyone, LocationAudience::Nobody, 'Owner');
        $viewer = User::factory()->approved()->create();

        $response = $this->actingAs($viewer)->getJson("/api/users/{$owner->id}")->assertOk();

        $this->assertSame(
            ['level' => 'country', 'label' => 'United States', 'city' => null, 'region' => null, 'country_code' => 'US', 'country_name' => 'United States'],
            $response->json('data.location'),
        );
        $body = (string) $response->getContent();
        foreach (['Portland', 'Oregon', 'lat_e4', 'lng_e4', 'latitude', 'longitude', 'place_id', '"'.self::PORTLAND.'"', (string) self::PORTLAND] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
        $this->assertSame([], $this->actingAs($viewer)->getJson('/api/location/nearby')->json('data.people'));
    }

    public function test_admins_get_no_bypass_of_location_audiences(): void
    {
        $this->admin->forceFill(['place_id' => self::PORTLAND])->save();
        $owner = $this->located(self::BEAVERTON, LocationAudience::Everyone, LocationAudience::Followers, 'Owner');

        $this->actingAs($this->admin->fresh())->getJson("/api/users/{$owner->id}")
            ->assertJsonPath('data.location.level', 'country');
        $this->assertSame([], $this->getJson('/api/location/nearby')->json('data.people'));
    }

    public function test_view_as_previews_each_audience_level(): void
    {
        $owner = $this->located(self::PORTLAND, LocationAudience::Everyone, LocationAudience::Followers, 'Owner');

        $this->actingAs($owner)->getJson("/api/users/{$owner->id}?view_as=public")
            ->assertOk()
            ->assertJsonPath('data.location.level', 'country')
            ->assertJsonPath('data.location.city', null);
        $this->getJson("/api/users/{$owner->id}?view_as=follower")
            ->assertOk()
            ->assertJsonPath('data.location.level', 'city')
            ->assertJsonPath('data.location.label', 'Portland, Oregon, United States');

        $owner->forceFill(['location_country_audience' => 'mutuals', 'location_city_audience' => 'mutuals'])->save();
        // A follower preview is one-way, so it never passes Mutuals.
        $this->getJson("/api/users/{$owner->id}?view_as=follower")->assertJsonPath('data.location', null);
    }

    public function test_following_a_persona_does_not_grant_its_owners_location(): void
    {
        $owner = $this->located(self::PORTLAND, LocationAudience::Followers, LocationAudience::Followers, 'Owner');
        $persona = Character::factory()->for($owner)->audience(Audience::Everyone)->create(['is_linked' => true]);
        $viewer = $this->located(self::PORTLAND, LocationAudience::Nobody, LocationAudience::Nobody, 'Viewer');
        FollowRequest::query()->create([
            'requester_id' => $viewer->id,
            'recipient_id' => $owner->id,
            'recipient_character_id' => $persona->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}")->assertJsonPath('data.location', null);
        $this->assertSame([], $this->getJson('/api/location/nearby')->json('data.people'));
    }

    private function located(int $placeId, LocationAudience $country, LocationAudience $city, string $name): User
    {
        $user = User::factory()->approved()->create(['display_name' => $name]);
        $user->forceFill(['place_id' => $placeId, 'location_country_audience' => $country, 'location_city_audience' => $city])->save();

        return $user;
    }
}
