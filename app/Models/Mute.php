<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mute extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['user_id', 'muted_user_id', 'muted_character_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function mutedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'muted_user_id');
    }

    /** @return BelongsTo<Character, $this> */
    public function mutedCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'muted_character_id');
    }
}
