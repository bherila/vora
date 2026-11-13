<?php

namespace App\Http\Controllers\Location;

use App\Enums\LocationAudience;
use App\Http\Controllers\Controller;
use App\Http\Requests\Location\NearestPlaceRequest;
use App\Http\Requests\Location\SearchPlacesRequest;
use App\Http\Requests\Location\UpdateLocationRequest;
use App\Models\Place;
use App\Models\User;
use App\Services\Location\LocationPresenter;
use App\Services\Location\PlaceDirectory;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

/**
 * The account's optional city and who may see it. Locations are account-level
 * only; personas have none.
 */
class LocationController extends Controller
{
    public function __construct(
        private readonly PlaceDirectory $places,
        private readonly LocationPresenter $presenter,
    ) {}

    public function search(SearchPlacesRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->places->search((string) $request->validated('q'))
                ->map(fn (Place $place): array => $place->toPickerArray())
                ->values(),
        ]);
    }

    /**
     * Snaps browser coordinates to the nearest city. The coordinates are used
     * for this lookup only and never stored.
     */
    public function nearest(NearestPlaceRequest $request): JsonResponse
    {
        $place = $this->places->nearest((float) $request->validated('latitude'), (float) $request->validated('longitude'));

        return response()->json([
            'success' => true,
            'data' => $place?->toPickerArray(),
        ]);
    }

    public function update(UpdateLocationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $user->place_id = $data['place_id'] ?? null;
        $user->location_country_audience = LocationAudience::from($data['country_audience']);
        $user->location_city_audience = LocationAudience::from($data['city_audience']);
        try {
            $user->save();
        } catch (QueryException $e) {
            // The city was removed by an import between validation and save
            // (the foreign key rejects it). Nothing was saved.
            if ($user->place_id !== null && ! Place::query()->whereKey($user->place_id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'That city was just removed from the list. Choose another.',
                    'errors' => ['place_id' => ['That city was just removed from the list. Choose another.']],
                ], 422);
            }

            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => $user->place_id === null ? 'Location removed.' : 'Location saved.',
            'data' => $this->presenter->settings($user),
        ]);
    }
}
