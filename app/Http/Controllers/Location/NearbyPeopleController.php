<?php

namespace App\Http\Controllers\Location;

use App\Enums\LocationAudience;
use App\Http\Controllers\Controller;
use App\Http\Requests\Location\NearbyPeopleRequest;
use App\Models\Place;
use App\Models\User;
use App\Services\Location\LocationVisibility;
use App\Services\Location\PlaceDirectory;
use App\Services\Media\MediaResponseService;
use App\Services\Privacy\ProfileGate;
use App\Support\BlockGraph;
use App\Support\MuteGraph;
use App\Support\UserPresenter;
use Illuminate\Http\JsonResponse;

/**
 * People near the viewer's own city. Someone is listed only when the viewer
 * may see their profile and their city-level location; distance is measured
 * between city centres and reported in coarse bands, so it never reveals more
 * than the city they chose. Accounts only — personas carry no location.
 */
class NearbyPeopleController extends Controller
{
    /** @var list<int> */
    public const RADII_KM = [25, 50, 100, 250];

    public const DEFAULT_RADIUS_KM = 50;

    private const LIMIT = 100;

    /** @var list<int> */
    private const DISTANCE_BANDS_KM = [10, 25, 50, 100, 250];

    public function __construct(
        private readonly PlaceDirectory $places,
        private readonly LocationVisibility $visibility,
        private readonly ProfileGate $gate,
        private readonly MediaResponseService $mediaResponder,
    ) {}

    public function index(NearbyPeopleRequest $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $radius = (int) ($request->validated('radius') ?? self::DEFAULT_RADIUS_KM);
        $origin = $viewer->place_id !== null ? $viewer->place()->first() : null;

        if (! $origin instanceof Place) {
            return response()->json(['success' => true, 'data' => ['has_location' => false, 'radius_km' => $radius, 'people' => []]]);
        }

        $query = User::query()
            ->select('users.*')
            ->join('places', 'places.id', '=', 'users.place_id')
            ->whereKeyNot($viewer->id)
            ->whereNotNull('users.approved_at')
            ->active()
            ->where('users.location_city_audience', '!=', LocationAudience::Nobody->value);
        $this->places->withinBox($query, 'places', $origin->latitude, $origin->longitude, PlaceDirectory::degreesFor($radius));
        BlockGraph::visibleTo($query, $viewer, 'users.id');
        MuteGraph::excludeMutedIdentities($query, $viewer->id, 'users.id');

        $candidates = $query->with(['profilePicture', 'place.region', 'place.country'])->get();
        $canView = $this->gate->canViewMany($viewer, $candidates);
        $levels = $this->visibility->levelsFor($viewer, $candidates);

        $people = $candidates
            ->filter(fn (User $user): bool => ($canView[$user->id] ?? false) && ($levels[$user->id] ?? null) === LocationVisibility::CITY)
            ->map(function (User $user) use ($origin): array {
                /** @var Place $place */
                $place = $user->place;
                $km = $place->id === $origin->id
                    ? 0.0
                    : PlaceDirectory::distanceKm($origin->latitude, $origin->longitude, $place->latitude, $place->longitude);

                return ['user' => $user, 'place' => $place, 'km' => $km];
            })
            ->filter(fn (array $row): bool => $row['km'] <= $radius)
            ->sortBy([
                fn (array $a, array $b): int => $a['km'] <=> $b['km'],
                fn (array $a, array $b): int => strcasecmp($this->name($a['user']), $this->name($b['user'])),
            ])
            ->take(self::LIMIT)
            ->map(fn (array $row): array => [
                'id' => $row['user']->id,
                'display_name' => $this->name($row['user']),
                'avatar_url' => UserPresenter::avatarUrl($row['user'], $this->mediaResponder, $viewer),
                'user_type' => $row['user']->user_type,
                'gender' => $row['user']->gender,
                'location' => LocationVisibility::payload($row['place'], LocationVisibility::CITY),
                'same_city' => $row['place']->id === $origin->id,
                'within_km' => $row['place']->id === $origin->id ? null : self::band($row['km']),
            ])
            ->values();

        return response()->json(['success' => true, 'data' => [
            'has_location' => true,
            'radius_km' => $radius,
            'people' => $people,
        ]]);
    }

    private function name(User $user): string
    {
        return $user->display_name ?: $user->name;
    }

    private static function band(float $km): int
    {
        foreach (self::DISTANCE_BANDS_KM as $band) {
            if ($km <= $band) {
                return $band;
            }
        }

        return (int) (ceil($km / 50) * 50);
    }
}
