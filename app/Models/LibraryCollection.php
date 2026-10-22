<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class LibraryCollection extends Model
{
    protected $fillable = ['name', 'description', 'related_character_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['related_persona_linked' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (LibraryCollection $collection): void {
            $collection->ulid ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Character, $this> */
    public function relatedCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'related_character_id');
    }

    /** @return BelongsToMany<LibraryItem, $this> */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(LibraryItem::class, 'library_collection_items');
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'library_collection_members');
    }
}
