<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Interest extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'parent_interest_id',
    ];

    /**
     * @return BelongsTo<Interest, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Interest::class, 'parent_interest_id');
    }

    /**
     * @return HasMany<Interest, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Interest::class, 'parent_interest_id');
    }

    /**
     * @return HasMany<InterestRating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(InterestRating::class);
    }
}
