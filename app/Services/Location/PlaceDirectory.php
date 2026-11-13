<?php

namespace App\Services\Location;

use App\Models\Place;
use App\Models\PlaceCountry;
use App\Models\PlaceRegion;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Search and reverse-lookup over the local GeoNames city table. Nothing here
 * calls an external service: typed queries and browser coordinates never leave
 * the server.
 *
 * Naming across the feature: "places" is the imported GeoNames data (the
 * `places*` tables, Place models, `places:import`, config/places.php);
 * "Location" is the user-facing feature built on it (this namespace, the
 * Location controllers and requests, routes/location.php, resources/js/location).
 */
class PlaceDirectory
{
    public function __construct(private readonly SettingsService $settings) {}

    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * Degree windows tried, smallest first, when snapping a coordinate to its
     * nearest city. The widest still covers remote areas of the gazetteer.
     */
    private const SNAP_WINDOWS = [0.5, 2.0, 8.0];

    /**
     * Normalizes a name for prefix search: lower-case ASCII, single spaces.
     */
    public static function searchKey(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($value))));
    }

    /**
     * Cities whose name starts with the first comma-separated part of $query,
     * most populous first. Later parts narrow by region or country, so
     * "portland, or" or "paris, fr" find the intended city.
     *
     * @return Collection<int, Place>
     */
    public function search(string $query, int $limit = 10): Collection
    {
        $parts = array_values(array_filter(
            array_map(self::searchKey(...), explode(',', $query)),
            static fn (string $part): bool => $part !== '',
        ));
        if ($parts === []) {
            return collect();
        }

        $builder = $this->current(Place::query())
            ->with(['region', 'country'])
            ->where('search_name', 'like', self::likePrefix($parts[0]))
            ->orderByDesc('population')
            ->orderBy('id');

        foreach (array_slice($parts, 1, 2) as $qualifier) {
            $prefix = self::likePrefix($qualifier);
            $builder->where(function (Builder $q) use ($prefix, $qualifier): void {
                $q->whereIn('region_id', PlaceRegion::query()->select('id')->where('search_name', 'like', $prefix))
                    ->orWhereIn('country_code', PlaceCountry::query()->select('code')->where('search_name', 'like', $prefix))
                    ->orWhere('country_code', Str::upper($qualifier));
            });
        }

        return $builder->limit($limit)->get();
    }

    /**
     * Whether a city is offered to people choosing a location: in the latest
     * complete import, not merely kept because someone already uses it.
     */
    public function isOffered(int $id): bool
    {
        return $this->current(Place::query())->whereKey($id)->exists();
    }

    /**
     * The city nearest a coordinate, or null when none is within the widest
     * search window (e.g. open ocean, or an empty gazetteer). With $anywhere,
     * falls back to scanning every current city, so it only returns null for
     * an empty gazetteer; imports use it to move people off dropped cities.
     */
    public function nearest(float $latitude, float $longitude, bool $anywhere = false): ?Place
    {
        foreach (self::SNAP_WINDOWS as $window) {
            $best = $this->closest($latitude, $longitude, $window);
            if (! $best instanceof Place) {
                continue;
            }

            // A closer city can sit just outside the square window (near its
            // corner the window reaches farther than at its edges), so check
            // the whole circle whose radius is the best distance found.
            $km = self::distanceKm($latitude, $longitude, $best->latitude, $best->longitude);

            return $this->closest($latitude, $longitude, self::degreesFor($km)) ?? $best;
        }

        return $anywhere ? $this->closestAnywhere($latitude, $longitude) : null;
    }

    private function closestAnywhere(float $latitude, float $longitude): ?Place
    {
        $bestId = null;
        $bestKm = INF;
        $this->current(Place::query())->select(['id', 'lat_e4', 'lng_e4'])->chunkById(2000, function ($places) use ($latitude, $longitude, &$bestId, &$bestKm): void {
            foreach ($places as $place) {
                $km = self::distanceKm($latitude, $longitude, $place->latitude, $place->longitude);
                if ($km < $bestKm) {
                    $bestKm = $km;
                    $bestId = $place->id;
                }
            }
        });

        return $bestId !== null ? Place::query()->with(['region', 'country'])->find($bestId) : null;
    }

    private function closest(float $latitude, float $longitude, float $latitudeDegrees): ?Place
    {
        /** @var Place|null */
        return $this->withinBox($this->current(Place::query())->with(['region', 'country']), 'places', $latitude, $longitude, $latitudeDegrees)
            ->get()
            ->sortBy(fn (Place $place): float => self::distanceKm($latitude, $longitude, $place->latitude, $place->longitude))
            ->first();
    }

    /**
     * Great-circle distance in kilometres (haversine).
     */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    /**
     * Degrees of latitude spanning $km, for {@see self::withinBox()}.
     */
    public static function degreesFor(float $km): float
    {
        return rad2deg($km / self::EARTH_RADIUS_KM);
    }

    /**
     * Constrains a query on `places` (directly, or joined as $table) to a
     * latitude/longitude box enclosing a circle around a point. The box is the cheap, indexable
     * prefilter; callers compute exact distances in PHP so the query stays
     * portable across SQLite and MariaDB.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function withinBox(Builder $query, string $table, float $latitude, float $longitude, float $latitudeDegrees): Builder
    {
        $scale = Place::COORDINATE_SCALE;
        // The box encloses every point within $latitudeDegrees of arc: the
        // widest longitude offset on that circle is asin(sin(r) / cos(lat)),
        // and a circle reaching a pole spans every longitude.
        $ratio = sin(deg2rad(min(90.0, $latitudeDegrees))) / max(cos(deg2rad($latitude)), 1e-9);
        $longitudeDegrees = $ratio >= 1.0 ? 180.0 : rad2deg(asin($ratio));

        $query->whereBetween("{$table}.lat_e4", [
            (int) floor(($latitude - $latitudeDegrees) * $scale),
            (int) ceil(($latitude + $latitudeDegrees) * $scale),
        ]);
        if ($longitudeDegrees >= 180.0) {
            return $query;
        }

        $west = (int) floor(($longitude - $longitudeDegrees) * $scale);
        $east = (int) ceil(($longitude + $longitudeDegrees) * $scale);
        $min = -180 * $scale;
        $max = 180 * $scale;
        $column = "{$table}.lng_e4";

        // Split the window where it crosses the antimeridian.
        return $query->where(function (Builder $q) use ($west, $east, $min, $max, $column): void {
            if ($west < $min) {
                $q->whereBetween($column, [$min, $east])->orWhereBetween($column, [$west + 2 * $max, $max]);
            } elseif ($east > $max) {
                $q->whereBetween($column, [$west, $max])->orWhereBetween($column, [$min, $east - 2 * $max]);
            } else {
                $q->whereBetween($column, [$west, $east]);
            }
        });
    }

    /**
     * Only cities in the latest complete import. Cities a later import dropped
     * but someone still uses stay in the table for that person's profile; they
     * are not offered to anyone else.
     *
     * @param  Builder<Place>  $query
     * @return Builder<Place>
     */
    private function current(Builder $query): Builder
    {
        $batch = $this->settings->getInt(PlaceImporter::CURRENT_BATCH_SETTING);

        return $batch > 0 ? $query->where('places.import_batch', '>=', $batch) : $query;
    }

    private static function likePrefix(string $value): string
    {
        return addcslashes($value, '\\%_').'%';
    }
}
