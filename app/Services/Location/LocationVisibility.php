<?php

namespace App\Services\Location;

use App\Enums\LocationAudience;
use App\Models\FollowRequest;
use App\Models\Place;
use App\Models\User;
use App\Support\FollowGraph;
use Illuminate\Support\Collection;

/**
 * Decides how much of an account's location a viewer may see: the city, only
 * the country, or nothing. Each level has its own audience
 * ({@see LocationAudience}); there is no admin bypass, because the owner chose
 * these audiences explicitly.
 *
 * Callers must still apply the profile gate and block graph: a location is
 * only ever shown alongside a profile the viewer is already allowed to see.
 * Locations belong to accounts only — personas never carry one.
 */
class LocationVisibility
{
    public const CITY = 'city';

    public const COUNTRY = 'country';

    /**
     * Single-owner form. Routes through FollowGraph so a /me view-as preview
     * sees what the simulated viewer would.
     *
     * @return self::CITY|self::COUNTRY|null
     */
    public function levelFor(User $viewer, User $owner): ?string
    {
        if ($owner->place_id === null) {
            return null;
        }
        if ($viewer->id === $owner->id) {
            return self::CITY;
        }

        return $this->level(
            $owner,
            FollowGraph::follows($viewer->id, $owner->id),
            FollowGraph::follows($owner->id, $viewer->id),
        );
    }

    /**
     * Batched form for listings: two queries regardless of list size.
     *
     * @param  Collection<int, User>  $owners
     * @return array<int, self::CITY|self::COUNTRY|null> keyed by owner id
     */
    public function levelsFor(User $viewer, Collection $owners): array
    {
        $ids = $owners->pluck('id')->all();
        $viewerFollows = FollowRequest::query()
            ->where('requester_id', $viewer->id)
            ->whereIn('recipient_id', $ids)
            ->whereNull('recipient_character_id')
            ->where('status', FollowRequest::STATUS_ACCEPTED)
            ->pluck('recipient_id')->map(fn ($id): int => (int) $id)->flip();
        $followsViewer = FollowRequest::query()
            ->whereIn('requester_id', $ids)
            ->where('recipient_id', $viewer->id)
            ->whereNull('recipient_character_id')
            ->where('status', FollowRequest::STATUS_ACCEPTED)
            ->pluck('requester_id')->map(fn ($id): int => (int) $id)->flip();

        $levels = [];
        foreach ($owners as $owner) {
            $levels[$owner->id] = match (true) {
                $owner->place_id === null => null,
                $owner->id === $viewer->id => self::CITY,
                default => $this->level($owner, $viewerFollows->has($owner->id), $followsViewer->has($owner->id)),
            };
        }

        return $levels;
    }

    /**
     * What a viewer at $level may be shown. A country-level payload carries no
     * place id or coordinates, which would identify the city.
     *
     * @return array{level: string, label: string, city: string|null, region: string|null, country_code: string, country_name: string}|null
     */
    public static function payload(?Place $place, ?string $level): ?array
    {
        if (! $place instanceof Place || $level === null) {
            return null;
        }

        if ($level === self::COUNTRY) {
            return [
                'level' => self::COUNTRY,
                'label' => $place->countryName(),
                'city' => null,
                'region' => null,
                'country_code' => $place->country_code,
                'country_name' => $place->countryName(),
            ];
        }

        return [
            'level' => self::CITY,
            'label' => $place->label(),
            'city' => $place->name,
            'region' => $place->regionName(),
            'country_code' => $place->country_code,
            'country_name' => $place->countryName(),
        ];
    }

    private function level(User $owner, bool $viewerFollowsOwner, bool $ownerFollowsViewer): ?string
    {
        if ($owner->location_city_audience->admits($viewerFollowsOwner, $ownerFollowsViewer)) {
            return self::CITY;
        }
        if ($owner->location_country_audience->admits($viewerFollowsOwner, $ownerFollowsViewer)) {
            return self::COUNTRY;
        }

        return null;
    }
}
