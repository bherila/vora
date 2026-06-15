<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterInterestRating extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'character_id',
        'interest_id',
        'level',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'level' => 'integer',
    ];

    /**
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /**
     * @return BelongsTo<Interest, $this>
     */
    public function interest(): BelongsTo
    {
        return $this->belongsTo(Interest::class);
    }
}
