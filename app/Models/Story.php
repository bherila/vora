<?php

namespace App\Models;

use App\Enums\Audience;
use App\Enums\ModerationStatus;
use App\Enums\StoryStatus;
use App\Enums\StoryType;
use App\Traits\HasPrivacyPolicy;
use App\Traits\Moderatable;
use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A user-authored, markdown text story. Either a single long-form body or a
 * choose-your-own-adventure graph of {@see StoryNode} passages connected by
 * {@see StoryChoice} edges.
 *
 * Privacy (audience) and admin review (moderation) reuse the shared
 * HasPrivacyPolicy/Moderatable traits, exactly like {@see Media}. A story is
 * owned by a single user but may have additional accepted co-authors.
 */
class Story extends Model
{
    use HasFactory;
    use HasPrivacyPolicy;
    use Moderatable;
    use SerializesDatesAsLocal;
    use SoftDeletes;

    /**
     * Owner-settable attributes only. Moderation columns are written solely
     * through the Moderatable trait.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'ulid',
        'title',
        'type',
        'status',
        'body',
        'audience',
        'discoverable',
        'announce_on_approval',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StoryType::class,
            'status' => StoryStatus::class,
            'audience' => Audience::class,
            'discoverable' => 'boolean',
            'announce_on_approval' => 'boolean',
            'moderation_status' => ModerationStatus::class,
            'moderated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function isCyoa(): bool
    {
        return $this->type === StoryType::Cyoa;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<StoryNode, $this>
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(StoryNode::class);
    }

    /**
     * @return HasMany<StoryChoice, $this>
     */
    public function choices(): HasMany
    {
        return $this->hasMany(StoryChoice::class);
    }

    /**
     * @return HasMany<StoryAuthor, $this>
     */
    public function authors(): HasMany
    {
        return $this->hasMany(StoryAuthor::class);
    }

    /**
     * @return HasMany<StoryInvolvement, $this>
     */
    public function involvements(): HasMany
    {
        return $this->hasMany(StoryInvolvement::class);
    }

    /**
     * @return BelongsToMany<Interest, $this>
     */
    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(Interest::class, 'story_interests')->withTimestamps();
    }

    /**
     * Restrict a listing to rows tagged with at least one of the given interest
     * ids. An empty list is a no-op, matching Media discovery filtering.
     *
     * @param  Builder<Story>  $query
     * @param  list<int>  $interestIds
     * @return Builder<Story>
     */
    public function scopeWithAnyInterest(Builder $query, array $interestIds): Builder
    {
        if ($interestIds === []) {
            return $query;
        }

        return $query->whereHas('interests', function (Builder $q) use ($interestIds): void {
            $q->whereIn('interests.id', $interestIds);
        });
    }

    /**
     * Whether the given user is the owner or an accepted co-author and may edit
     * the story's content.
     */
    public function isAuthoredBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->user_id === $user->id) {
            return true;
        }

        return $this->authors()
            ->where('user_id', $user->id)
            ->where('status', StoryAuthor::STATUS_ACCEPTED)
            ->exists();
    }
}
