<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A GeoNames first-level administrative division (state, province, region).
 *
 * @property int $id
 * @property string $country_code
 * @property string $code GeoNames admin1 code
 * @property string $name
 * @property string $search_name
 */
class PlaceRegion extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
