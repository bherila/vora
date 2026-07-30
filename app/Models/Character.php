<?php

namespace App\Models;

use App\Enums\Audience;
use App\Services\Story\StoryService;
use App\Traits\HasPrivacyPolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Character extends Model
{
    use HasFactory;
    use HasPrivacyPolicy {
        isViewableBy as private isViewableByAudience;
    }
    use SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Character $character): void {
            $character->ulid ??= (string) Str::ulid();
            $character->is_linked ??= true;
        });

        // A Separate persona must never fall back to the owner's interests. That
        // would expose the owner's exact interest fingerprint through matching
        // and discovery surfaces intended to keep the two identities apart.
        static::saving(function (Character $character): void {
            if ($character->is_linked === false) {
                $character->inherit_interests = false;
            }
        });

        // Characters soft-delete for admin retention. Only a force delete should
        // drop story "involves" tags, otherwise restore would not put the
        // character back exactly where it was.
        static::deleting(function (Character $character): void {
            // A database cascade only runs on a force delete. Persona-scoped
            // follows must also disappear on a soft delete; retaining them or
            // nulling their scope would turn them into account-wide follows.
            $character->recipientFollowRequests()->delete();

            if (! $character->isForceDeleting()) {
                return;
            }

            $character->storyInvolvements()->delete();
        });

        // Reassigning a character to a new owner can strand its story "involves"
        // tags in stories the new owner does not author. Prune through the same
        // allowed-involvables rule as the rest of the app rather than deleting
        // every tag, so a tag in a story the new owner *does* author (i.e. still
        // valid) is kept. No user-facing path changes user_id today; this guards
        // the invariant for future admin/import/maintenance paths.
        static::updated(function (Character $character): void {
            if (! $character->wasChanged('user_id')) {
                return;
            }

            // Persona followers consented to this identity under its previous
            // owner. A transfer must not carry those edges to another account.
            $character->recipientFollowRequests()->delete();

            $service = app(StoryService::class);
            $storyIds = $character->storyInvolvements()->pluck('story_id')->unique();
            Story::query()->whereIn('id', $storyIds)->get()
                ->each(fn (Story $story) => $service->pruneDisallowedInvolvements($story));
        });
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ulid',
        'user_id',
        'display_name',
        'description',
        'is_linked',
        'audience',
        'discoverable',
        'gender',
        'gender_other',
        'user_type',
        'user_type_other',
        'inherit_interests',
        'profile_picture_media_id',
    ];

    // The table still has legacy preferred_user_types/preferred_genders
    // columns, but personas do not have a viewing context. Keeping them out of
    // mass assignment and casts prevents the dormant schema from becoming an
    // accidental editing contract.

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => Audience::class,
            'discoverable' => 'boolean',
            'is_linked' => 'boolean',
            'inherit_interests' => 'boolean',
            'profile_picture_media_id' => 'integer',
        ];
    }

    /** @return HasMany<FollowRequest, $this> */
    public function recipientFollowRequests(): HasMany
    {
        return $this->hasMany(FollowRequest::class, 'recipient_character_id');
    }

    /**
     * Blocks that explicitly name this persona. They survive soft deletion so
     * restoring a persona cannot silently undo a user's safety choice.
     *
     * @return HasMany<Block, $this>
     */
    public function identityBlocks(): HasMany
    {
        return $this->hasMany(Block::class, 'blocked_character_id');
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
     * Characters inherit their audience decision from the shared privacy policy,
     * but an unapproved or inactive owner's personas are unavailable everywhere.
     */
    public function isViewableBy(?User $viewer): bool
    {
        $owner = $this->user;

        if (! $owner instanceof User || ! $owner->isApproved() || ! $owner->isActive()) {
            return false;
        }

        return $this->isViewableByAudience($viewer);
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function profilePicture(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'profile_picture_media_id');
    }

    /**
     * Uploaded media associated with this character. These rows inherit the
     * character privacy policy; they do not carry an independent audience.
     *
     * @return HasMany<Media, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
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
