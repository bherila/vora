<?php

namespace App\Models;

use App\Enums\ModerationStatus;
use App\Support\BlockGraph;
use App\Traits\Moderatable;
use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A reply on a {@see Post}. Flat with one optional level of threading via
 * parent_id. Reuses the shared admin-review plumbing; comments publish
 * immediately and are moderated reactively, like posts.
 */
class PostComment extends Model
{
    use HasFactory;
    use Moderatable;
    use SerializesDatesAsLocal;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = ['post_id', 'user_id', 'parent_id', 'body', 'removed_by_user_id', 'removed_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'moderation_status' => ModerationStatus::class,
            'moderated_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PostComment $comment): void {
            $comment->ulid ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_user_id');
    }

    /**
     * Comments a viewer may see by their own review state: approved comments from
     * active accounts, plus the viewer's own (any review state). The source of
     * truth for a single comment's visibility — used by the reply-parent check and
     * composed into {@see scopeThreadVisibleTo} so they cannot leak moderation
     * state. Kept non-recursive so it can be reused for the parent check.
     *
     * @param  Builder<PostComment>  $query
     * @return Builder<PostComment>
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        if ($viewer instanceof User) {
            BlockGraph::commentsVisibleTo($query, $viewer, $query->getModel()->getTable());
        }

        return $query->whereNull($query->qualifyColumn('deleted_at'))->where(function (Builder $outer) use ($viewer): void {
            $outer->where(function (Builder $inner): void {
                $inner->where('moderation_status', ModerationStatus::Approved->value)
                    ->whereHas('user', fn (Builder $u) => $u->active());
            });

            if ($viewer !== null) {
                $outer->orWhere('user_id', $viewer->id);
            }
        })->whereNull('removed_at');
    }

    /**
     * Neutral tombstones expose no author or body, but their existence must
     * still obey moderation, account-state, and block visibility. Keeping this
     * in one scope prevents a removed/deleted OR branch from bypassing #173's
     * identity graph.
     *
     * @param  Builder<PostComment>  $query
     * @return Builder<PostComment>
     */
    public function scopeTombstoneVisibleTo(Builder $query, ?User $viewer): Builder
    {
        if ($viewer instanceof User) {
            BlockGraph::commentsVisibleTo($query, $viewer, $query->getModel()->getTable());
        }

        return $query
            ->where('moderation_status', ModerationStatus::Approved->value)
            ->whereHas('user', fn (Builder $user): Builder => $user->active());
    }

    /**
     * Thread-aware visibility: a comment the viewer may see whose parent (if any)
     * the viewer may also see. Moderating a top-level comment away therefore also
     * hides its now-orphaned replies, so the listing never shows a reply whose
     * parent is gone. Used by the comment listing and the post's comment_count so
     * the two stay in agreement. Non-recursive — threading is one level deep, so
     * the parent check applies {@see scopeVisibleTo} to a top-level comment.
     *
     * @param  Builder<PostComment>  $query
     * @return Builder<PostComment>
     */
    public function scopeThreadVisibleTo(Builder $query, ?User $viewer): Builder
    {
        return $query
            ->visibleTo($viewer)
            ->where(function (Builder $outer) use ($viewer): void {
                $outer->whereNull('parent_id')
                    ->orWhereHas('parent', fn (Builder $parent) => $parent->visibleTo($viewer))
                    ->orWhereHas('parentWithTrashed', function (Builder $parent) use ($viewer): void {
                        $parent->tombstoneVisibleTo($viewer)
                            ->where(fn (Builder $state) => $state->whereNotNull('deleted_at')->orWhereNotNull('removed_at'));
                    });
            });
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PostComment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(PostComment::class, 'parent_id');
    }

    /** @return BelongsTo<PostComment, $this> */
    public function parentWithTrashed(): BelongsTo
    {
        return $this->belongsTo(PostComment::class, 'parent_id')->withTrashed();
    }

    /**
     * @return HasMany<PostComment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(PostComment::class, 'parent_id');
    }
}
