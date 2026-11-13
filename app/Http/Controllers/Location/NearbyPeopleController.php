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
use Illuminate\Support\Collection;

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
        // Candidates authorized per pass; also bounds each IN (...) list.
        private readonly int $chunkSize = 500,
        private readonly int $limit = self::LIMIT,
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

        // Work through the box in bounded chunks: authorize and measure each
        // chunk, and keep only the nearest permitted people seen so far. Memory
        // and query size stay bounded however dense the area is, and the
        // result is still the true nearest $limit, not the first ones found.
        $best = [];
        $query->addSelect(['places.lat_e4 as candidate_lat_e4', 'places.lng_e4 as candidate_lng_e4'])
            ->chunkById($this->chunkSize, function (Collection $chunk) use ($viewer, $origin, $radius, &$best): void {
                $canView = $this->gate->canViewMany($viewer, $chunk);
                $levels = $this->visibility->levelsFor($viewer, $chunk);
                foreach ($chunk as $user) {
                    if (! ($canView[$user->id] ?? false) || ($levels[$user->id] ?? null) !== LocationVisibility::CITY) {
                        continue;
                    }
                    $km = $user->place_id === $origin->id ? 0.0 : PlaceDirectory::distanceKm(
                        $origin->latitude,
                        $origin->longitude,
                        $user->candidate_lat_e4 / Place::COORDINATE_SCALE,
                        $user->candidate_lng_e4 / Place::COORDINATE_SCALE,
                    );
                    if ($km <= $radius) {
                        $best[] = ['id' => $user->id, 'km' => $km, 'name' => $this->name($user)];
                    }
                }
                usort($best, fn (array $a, array $b): int => ($a['km'] <=> $b['km'])
                    ?: strcasecmp($a['name'], $b['name'])
                    ?: ($a['id'] <=> $b['id']));
                $best = array_slice($best, 0, $this->limit);
            }, 'users.id', 'id');

        // Only the final people are loaded in full.
        $users = User::query()
            ->with(['profilePicture', 'place.region', 'place.country'])
            ->whereIn('id', array_column($best, 'id'))
            ->get()
            ->keyBy('id');

        $people = collect($best)
            ->filter(fn (array $row): bool => $users->has($row['id']) && $users[$row['id']]->place instanceof Place)
            ->map(function (array $row) use ($users, $viewer, $origin): array {
                /** @var User $user */
                $user = $users[$row['id']];
                /** @var Place $place */
                $place = $user->place;

                return [
                    'id' => $user->id,
                    'display_name' => $row['name'],
                    'avatar_url' => UserPresenter::avatarUrl($user, $this->mediaResponder, $viewer),
                    'user_type' => $user->user_type,
                    'gender' => $user->gender,
                    'location' => LocationVisibility::payload($place, LocationVisibility::CITY),
                    'same_city' => $place->id === $origin->id,
                    'within_km' => $place->id === $origin->id ? null : self::band($row['km']),
                ];
            })
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
