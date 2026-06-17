<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Character extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        // Characters hard-delete; drop any story "involves" tags pointing at this
        // character so they cannot dangle (story_involvements has no FK on the
        // polymorphic columns).
        static::deleting(function (Character $character): void {
            $character->storyInvolvements()->delete();
        });

        // Reassigning a character to a new owner would strand its story "involves"
        // tags in stories the new owner does not author. No user-facing path
        // changes user_id today, but guard the invariant at the model so any
        // future admin/import/maintenance path cannot leave invalid tags behind.
        static::updated(function (Character $character): void {
            if ($character->wasChanged('user_id')) {
                $character->storyInvolvements()->delete();
            }
        });
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'display_name',
        'description',
        'gender',
        'gender_other',
        'user_type',
        'user_type_other',
        'preferred_user_types',
        'preferred_genders',
        'inherit_interests',
        'profile_picture_media_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preferred_user_types' => 'array',
            'preferred_genders' => 'array',
            'inherit_interests' => 'boolean',
            'profile_picture_media_id' => 'integer',
        ];
    }

    /**
     * Per-character interest overrides. Only consulted when
     * {@see static::$inherit_interests} is false; otherwise the character falls
     * back to the owning user's profile interest ratings.
     *
     * @return HasMany<InterestRating, $this>
     */
    public function interestRatings(): HasMany
    {
        return $this->hasMany(InterestRating::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function profilePicture(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'profile_picture_media_id');
    }

    /**
     * Story "involves" tags pointing at this character.
     *
     * @return MorphMany<StoryInvolvement, $this>
     */
    public function storyInvolvements(): MorphMany
    {
        return $this->morphMany(StoryInvolvement::class, 'involvable');
    }
}
