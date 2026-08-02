<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Interest extends Model
{
    private const SLUG_MAX_LENGTH = 255;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'parent_interest_id',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function booted(): void
    {
        // Admin creation supplies the slug explicitly. This fallback keeps
        // seeders/imports safe without ever regenerating it on rename.
        static::creating(function (Interest $interest): void {
            if ($interest->slug !== null && $interest->slug !== '') {
                return;
            }

            $interest->slug = self::generateUniqueSlug($interest->name);
        });
    }

    public static function generateUniqueSlug(string $value): string
    {
        $base = Str::slug($value) ?: 'interest';
        $base = rtrim(Str::limit($base, self::SLUG_MAX_LENGTH, ''), '-_') ?: 'interest';
        $slug = $base;
        $number = 2;

        while (self::query()->where('slug', $slug)->exists()) {
            $suffix = '-'.$number++;
            $stem = rtrim(Str::limit($base, self::SLUG_MAX_LENGTH - strlen($suffix), ''), '-_');
            $slug = ($stem === '' ? 'interest' : $stem).$suffix;
        }

        return $slug;
    }

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
