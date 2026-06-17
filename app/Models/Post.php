<?php

namespace App\Models;

use App\Enums\Audience;
use App\Enums\ModerationStatus;
use App\Traits\HasPrivacyPolicy;
use App\Traits\Moderatable;
use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A short text post — the building block of the follower feed. Reuses the shared
 * privacy (audience/discoverable) and admin-review plumbing, exactly like Media
 * and Story, plus a polymorphic attachment to a Character, Interest, Media, or
 * Story the author owns.
 */
class Post extends Model
{
    use HasFactory;
    use HasPrivacyPolicy;
    use Moderatable;
    use SerializesDatesAsLocal;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'character_id',
        'ulid',
        'body',
        'audience',
        'discoverable',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => Audience::class,
            'discoverable' => 'boolean',
            'moderation_status' => ModerationStatus::class,
            'moderated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The persona this post is published as, if any. Ownership stays with the
     * user; the character is only the surfaced identity.
     *
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /**
     * @return HasMany<PostAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(PostAttachment::class);
    }

    /**
     * @return HasMany<PostReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(PostReaction::class);
    }

    /**
     * @return HasMany<PostComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class);
    }

    /**
     * Load the engagement summary the presenter needs in one place — reaction
     * count, whether $viewer reacted, and comment count — without an N+1 across a
     * listing.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeWithEngagementCounts(Builder $query, ?User $viewer): Builder
    {
        return $query
            ->withCount('reactions')
            // Count only the comments this viewer may see, so comment_count agrees
            // with the comments listing (including hiding replies orphaned by a
            // moderated-away parent) and never leaks moderation state.
            ->withCount(['comments' => fn (Builder $inner) => $inner->threadVisibleTo($viewer)])
            ->withCount(['reactions as viewer_reaction_count' => function (Builder $inner) use ($viewer): void {
                $inner->where('user_id', $viewer?->id);
            }]);
    }

    /**
     * Admin variant of {@see scopeWithEngagementCounts}: comment_count reflects
     * every comment regardless of moderation state, so the review payload's count
     * matches the comment-moderation listing instead of under-reporting once a
     * comment is rejected. Reaction state is unchanged.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeWithAdminEngagementCounts(Builder $query, ?User $viewer): Builder
    {
        return $query
            ->withCount('reactions')
            ->withCount('comments')
            ->withCount(['reactions as viewer_reaction_count' => function (Builder $inner) use ($viewer): void {
                $inner->where('user_id', $viewer?->id);
            }]);
    }
}
