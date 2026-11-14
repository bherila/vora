<?php

namespace Tests\Feature\Location;

use App\Models\Place;
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

        $this->actingAs($user)->getJson('/api/location/places?q=portl')
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Portland, Oregon, United States')
            ->assertJsonPath('data.1.label', 'Portland, Maine, United States');

        $this->getJson('/api/location/places?q=portland,%20maine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 4975802);

        $this->getJson('/api/location/places?q=paris,%20fr')->assertJsonPath('data.0.country_name', 'France');
        // Accents are folded for search and kept for display.
        $this->getJson('/api/location/places?q=saint-etienne')->assertJsonPath('data.0.name', 'Saint-Étienne-du-Rouvray');
        // LIKE wildcards are literal.
        $this->getJson('/api/location/places?q=%25%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/location/places?q=p')->assertUnprocessable();
    }

    public function test_nearest_snaps_coordinates_to_a_city_without_storing_them(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->postJson('/api/location/places/nearest', ['latitude' => 45.5, 'longitude' => -122.7])
            ->assertOk()
            ->assertJsonPath('data.id', 5746545);
        // Across the antimeridian: Taveuni East is at -179.9.
        $this->postJson('/api/location/places/nearest', ['latitude' => -16.8, 'longitude' => 179.95])
            ->assertJsonPath('data.id', 9999993);
        // Open ocean, nothing within the widest window.
        $this->postJson('/api/location/places/nearest', ['latitude' => -50, 'longitude' => -140])
            ->assertOk()
            ->assertJsonPath('data', null);
        $this->postJson('/api/location/places/nearest', ['latitude' => 91, 'longitude' => 0])->assertUnprocessable();

        $this->assertNull($user->fresh()->place_id);
    }

    public function test_nearest_checks_beyond_the_corner_of_the_first_window(): void
    {
        // From (0, 0): the corner city is inside the first 0.5° window but
        // about 77 km away; the edge city is outside it and about 57 km away.
        $batch = (int) Place::query()->max('import_batch');
        Place::query()->create(['id' => 9000001, 'name' => 'Corner', 'search_name' => 'corner', 'country_code' => 'FJ', 'lat_e4' => 4900, 'lng_e4' => 4900, 'import_batch' => $batch]);
        Place::query()->create(['id' => 9000002, 'name' => 'Edge', 'search_name' => 'edge', 'country_code' => 'FJ', 'lat_e4' => 5100, 'lng_e4' => 0, 'import_batch' => $batch]);
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->postJson('/api/location/places/nearest', ['latitude' => 0, 'longitude' => 0])
            ->assertOk()
            ->assertJsonPath('data.id', 9000002);
    }

    public function test_owner_sets_and_clears_location_with_separate_audiences(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->putJson('/api/location', [
            'place_id' => 5746545,
            'country_audience' => 'everyone',
            'city_audience' => 'mutuals',
        ])->assertOk()
            ->assertJsonPath('data.place.label', 'Portland, Oregon, United States')
            ->assertJsonPath('data.country_audience', 'everyone')
            ->assertJsonPath('data.city_audience', 'mutuals');

        $this->assertSame(5746545, $user->fresh()->place_id);

        $this->getJson('/api/account/export')->assertJsonPath('data.account.location.place.id', 5746545);

        $this->putJson('/api/location', [
            'place_id' => null,
            'country_audience' => 'nobody',
            'city_audience' => 'nobody',
        ])->assertOk()->assertJsonPath('data.place', null);
        $this->assertNull($user->fresh()->place_id);
    }

    public function test_city_audience_cannot_be_wider_than_country_audience(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->putJson('/api/location', [
            'place_id' => 5746545,
            'country_audience' => 'followers',
            'city_audience' => 'everyone',
        ])->assertUnprocessable()->assertJsonValidationErrors('city_audience');

        $this->putJson('/api/location', [
            'place_id' => 123,
            'country_audience' => 'everyone',
            'city_audience' => 'everyone',
        ])->assertUnprocessable()->assertJsonValidationErrors('place_id');

        $this->putJson('/api/location', [
            'place_id' => 5746545,
            'country_audience' => 'specific',
            'city_audience' => 'nobody',
        ])->assertUnprocessable()->assertJsonValidationErrors('country_audience');
    }

    public function test_location_endpoints_follow_the_profiles_module(): void
    {
        config(['instance.modules.profiles' => false]);
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->getJson('/api/location/places?q=portland')->assertNotFound();
        $this->getJson('/api/location/nearby')->assertNotFound();
        $this->putJson('/api/location', [
            'place_id' => 5746545, 'country_audience' => 'everyone', 'city_audience' => 'everyone',
        ])->assertNotFound();
        $this->assertNull($user->fresh()->place_id);
    }

    public function test_location_endpoints_require_an_approved_account(): void
    {
        $this->getJson('/api/location/places?q=portland')->assertUnauthorized();
        $pending = User::factory()->create(['approved_at' => null]);
        $this->actingAs($pending)->putJson('/api/location', [
            'place_id' => 5746545, 'country_audience' => 'everyone', 'city_audience' => 'everyone',
        ])->assertForbidden();
    }

    public function test_deleting_the_account_clears_its_location(): void
    {
        // Another admin keeps the last-admin guard out of the way.
        User::factory()->approved()->create(['is_admin' => true]);
        $user = User::factory()->approved()->create([
            'place_id' => 5746545, 'location_country_audience' => 'everyone', 'location_city_audience' => 'followers',
        ]);

        $this->actingAs($user)->postJson('/api/account/delete')->assertOk();

        $deleted = User::withTrashed()->findOrFail($user->id);
        $this->assertTrue($deleted->trashed());
        $this->assertNull($deleted->place_id);
        $this->assertSame('nobody', $deleted->location_country_audience->value);
        $this->assertSame('nobody', $deleted->location_city_audience->value);
    }
}
