<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class LibraryItem extends Model
{
    protected $fillable = ['media_id', 'source', 'title', 'body', 'source_item_ulid'];

    protected static function booted(): void
    {
        static::creating(function (LibraryItem $item): void {
            $item->ulid ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class)->withTrashed();
    }

    /** @return BelongsToMany<LibraryCollection, $this> */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(LibraryCollection::class, 'library_collection_items');
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'library_item_members');
    }
}
