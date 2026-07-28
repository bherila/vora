<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's authorship link to a {@see Story}. The creator holds the single
 * {@see self::ROLE_OWNER} row (already accepted); invited collaborators hold a
 * {@see self::ROLE_CO_AUTHOR} row that starts {@see self::STATUS_PENDING} until
 * accepted through the shared acceptance inbox.
 */
class StoryAuthor extends Model
{
    use HasFactory;

    public const ROLE_OWNER = 'owner';

    public const ROLE_CO_AUTHOR = 'co_author';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'story_id',
        'user_id',
        'character_id',
        'invited_by_user_id',
        'role',
        'status',
        'responded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'character_id' => 'integer',
            'responded_at' => 'datetime',
        ];
    }

    /**
     * Pending invites whose story owner is still active (not deactivated,
     * disabled, or deleted). Used by both the invite inbox/count API and the
     * navbar badge so they can never disagree. A soft-deleted owner drops out
     * via the relation's default scope.
     *
     * @param  Builder<StoryAuthor>  $query
     * @return Builder<StoryAuthor>
     */
    public function scopePendingForActiveOwner(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PENDING)
            ->whereHas('story', function (Builder $story): void {
                $story->whereHas('user', function (Builder $owner): void {
                    $owner->active();
                });
            });
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    /**
     * @return BelongsTo<Story, $this>
     */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The persona this author publishes the story as, or null for themselves.
     *
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
