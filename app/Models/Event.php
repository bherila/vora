<?php

namespace App\Models;

use App\Enums\EventVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Event extends Model
{
    protected $fillable = ['name', 'description', 'visibility', 'timezone', 'starts_at', 'ends_at'];

    protected static function booted(): void
    {
        static::creating(function (Event $event): void {
            $event->ulid ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return ['visibility' => EventVisibility::class, 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_user');
    }

    /** @return HasMany<Media, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
