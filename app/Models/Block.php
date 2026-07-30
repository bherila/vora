<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Block extends Model
{
    protected $fillable = [
        'blocker_id',
        'blocked_user_id',
        'blocked_character_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocker_id');
    }

    /** @return BelongsTo<User, $this> */
    public function blockedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_user_id');
    }

    /** @return BelongsTo<Character, $this> */
    public function blockedCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'blocked_character_id');
    }
}
