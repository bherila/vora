<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $code ISO 3166-1 alpha-2
 * @property string $name
 * @property string $search_name
 */
class PlaceCountry extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
