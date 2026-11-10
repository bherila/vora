<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A city from the locally imported GeoNames gazetteer. Coordinates are stored
 * as integers in 1/10,000 degree; use the latitude/longitude accessors.
 *
 * @property int $id GeoNames id
 * @property string $name
 * @property string $search_name lower-case ASCII form of the name, for prefix search
 * @property int|null $region_id
 * @property string $country_code ISO 3166-1 alpha-2
 * @property int $lat_e4
 * @property int $lng_e4
 * @property int $population
 * @property-read float $latitude
 * @property-read float $longitude
 * @property-read PlaceRegion|null $region
 * @property-read PlaceCountry|null $country
 */
class Place extends Model
{
    /** Stored coordinate units per degree. */
    public const COORDINATE_SCALE = 10000;

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'lat_e4' => 'integer',
            'lng_e4' => 'integer',
            'population' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PlaceRegion, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(PlaceRegion::class);
    }

    /**
     * @return BelongsTo<PlaceCountry, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(PlaceCountry::class, 'country_code', 'code');
    }

    /**
     * @return Attribute<float, never>
     */
    protected function latitude(): Attribute
    {
        return Attribute::get(fn (): float => $this->lat_e4 / self::COORDINATE_SCALE);
    }

    /**
     * @return Attribute<float, never>
     */
    protected function longitude(): Attribute
    {
        return Attribute::get(fn (): float => $this->lng_e4 / self::COORDINATE_SCALE);
    }

    public function regionName(): ?string
    {
        $name = $this->region?->name;

        return $name !== null && $name !== '' && $name !== $this->name ? $name : null;
    }

    public function countryName(): string
    {
        return $this->country->name ?? $this->country_code;
    }

    /**
     * "Portland, Oregon, United States". The region is omitted when GeoNames has
     * none, or when it repeats the city name (e.g. city-states).
     */
    public function label(): string
    {
        return implode(', ', array_filter([$this->name, $this->regionName(), $this->countryName()]));
    }

    /**
     * @return array{id: int, name: string, region: string|null, country_code: string, country_name: string, label: string}
     */
    public function toPickerArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'region' => $this->regionName(),
            'country_code' => $this->country_code,
            'country_name' => $this->countryName(),
            'label' => $this->label(),
        ];
    }
}
