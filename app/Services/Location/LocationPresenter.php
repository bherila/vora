<?php

namespace App\Services\Location;

use App\Models\Place;
use App\Models\User;

/**
 * The location payloads other features embed: the owner's own settings (profile
 * editor, data export) and what a viewer may see on a profile. Callers outside
 * the Location feature use only this class.
 */
class LocationPresenter
{
    public function __construct(private readonly LocationVisibility $visibility) {}

    /**
     * The owner's own location settings.
     *
     * @return array{place: array<string, mixed>|null, country_audience: string, city_audience: string}
     */
    public function settings(User $owner): array
    {
        return [
            'place' => $this->place($owner)?->toPickerArray(),
            'country_audience' => $owner->location_country_audience->value,
            'city_audience' => $owner->location_city_audience->value,
        ];
    }

    /**
     * The owner's location at the level the viewer may see, or null. Callers
     * must already have passed the profile gate for this viewer.
     *
     * @return array<string, mixed>|null
     */
    public function forViewer(User $viewer, User $owner): ?array
    {
        return LocationVisibility::payload($this->place($owner), $this->visibility->levelFor($viewer, $owner));
    }

    private function place(User $owner): ?Place
    {
        return $owner->place_id !== null
            ? $owner->place()->with(['region', 'country'])->first()
            : null;
    }
}
