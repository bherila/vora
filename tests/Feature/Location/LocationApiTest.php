<?php

namespace Tests\Feature\Location;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationApiTest extends TestCase
{
    use ImportsGeoNamesFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importGeoNamesFixture();
    }

    public function test_search_ranks_by_population_and_accepts_region_or_country_qualifiers(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->getJson('/api/places/search?q=portl')
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Portland, Oregon, United States')
            ->assertJsonPath('data.1.label', 'Portland, Maine, United States');

        $this->getJson('/api/places/search?q=portland,%20maine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 4975802);

        $this->getJson('/api/places/search?q=paris,%20fr')->assertJsonPath('data.0.country_name', 'France');
        // Accents are folded for search and kept for display.
        $this->getJson('/api/places/search?q=saint-etienne')->assertJsonPath('data.0.name', 'Saint-Étienne-du-Rouvray');
        // LIKE wildcards are literal.
        $this->getJson('/api/places/search?q=%25%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/places/search?q=p')->assertUnprocessable();
    }

    public function test_nearest_snaps_coordinates_to_a_city_without_storing_them(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->postJson('/api/places/nearest', ['latitude' => 45.5, 'longitude' => -122.7])
            ->assertOk()
            ->assertJsonPath('data.id', 5746545);
        // Across the antimeridian: Taveuni East is at -179.9.
        $this->postJson('/api/places/nearest', ['latitude' => -16.8, 'longitude' => 179.95])
            ->assertJsonPath('data.id', 9999993);
        // Open ocean, nothing within the widest window.
        $this->postJson('/api/places/nearest', ['latitude' => -50, 'longitude' => -140])
            ->assertOk()
            ->assertJsonPath('data', null);
        $this->postJson('/api/places/nearest', ['latitude' => 91, 'longitude' => 0])->assertUnprocessable();

        $this->assertNull($user->fresh()->place_id);
    }

    public function test_owner_sets_and_clears_location_with_separate_audiences(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->putJson('/api/account/location', [
            'place_id' => 5746545,
            'country_audience' => 'everyone',
            'city_audience' => 'mutuals',
        ])->assertOk()
            ->assertJsonPath('data.place.label', 'Portland, Oregon, United States')
            ->assertJsonPath('data.country_audience', 'everyone')
            ->assertJsonPath('data.city_audience', 'mutuals');

        $this->assertSame(5746545, $user->fresh()->place_id);

        $this->getJson('/api/account/export')->assertJsonPath('data.account.location.place.id', 5746545);

        $this->putJson('/api/account/location', [
            'place_id' => null,
            'country_audience' => 'nobody',
            'city_audience' => 'nobody',
        ])->assertOk()->assertJsonPath('data.place', null);
        $this->assertNull($user->fresh()->place_id);
    }

    public function test_city_audience_cannot_be_wider_than_country_audience(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->putJson('/api/account/location', [
            'place_id' => 5746545,
            'country_audience' => 'followers',
            'city_audience' => 'everyone',
        ])->assertUnprocessable()->assertJsonValidationErrors('city_audience');

        $this->putJson('/api/account/location', [
            'place_id' => 123,
            'country_audience' => 'everyone',
            'city_audience' => 'everyone',
        ])->assertUnprocessable()->assertJsonValidationErrors('place_id');

        $this->putJson('/api/account/location', [
            'place_id' => 5746545,
            'country_audience' => 'specific',
            'city_audience' => 'nobody',
        ])->assertUnprocessable()->assertJsonValidationErrors('country_audience');
    }

    public function test_location_endpoints_require_an_approved_account(): void
    {
        $this->getJson('/api/places/search?q=portland')->assertUnauthorized();
        $pending = User::factory()->create(['approved_at' => null]);
        $this->actingAs($pending)->putJson('/api/account/location', [
            'place_id' => 5746545, 'country_audience' => 'everyone', 'city_audience' => 'everyone',
        ])->assertForbidden();
    }
}
