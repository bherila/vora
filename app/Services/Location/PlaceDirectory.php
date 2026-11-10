<?php

namespace App\Services\Location;

use App\Models\Place;
use App\Models\PlaceCountry;
use App\Models\PlaceRegion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Search and reverse-lookup over the local GeoNames city table. Nothing here
 * calls an external service: typed queries and browser coordinates never leave
 * the server.
 */
class PlaceDirectory
{
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

        $builder = Place::query()
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
     * The city nearest a coordinate, or null when none is within the widest
     * search window (e.g. open ocean, or an empty gazetteer).
     */
    public function nearest(float $latitude, float $longitude): ?Place
    {
        foreach (self::SNAP_WINDOWS as $window) {
            $candidates = $this->withinBox(Place::query()->with(['region', 'country']), 'places', $latitude, $longitude, $window)->get();
            if ($candidates->isEmpty()) {
                continue;
            }

            /** @var Place */
            return $candidates
                ->sortBy(fn (Place $place): float => self::distanceKm($latitude, $longitude, $place->latitude, $place->longitude))
                ->first();
        }

        return null;
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
     * latitude/longitude box around a point. The box is the cheap, indexable
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
        $cos = cos(deg2rad($latitude));
        $longitudeDegrees = $cos > 0.01 ? min(180.0, $latitudeDegrees / $cos) : 180.0;

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

    private static function likePrefix(string $value): string
    {
        return addcslashes($value, '\\%_').'%';
    }
}
