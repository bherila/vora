<?php

namespace App\Models;

use App\Enums\ModerationStatus;
use App\Traits\Moderatable;
use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * @var list<string>
     */
    protected $fillable = ['post_id', 'user_id', 'parent_id', 'body'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'moderation_status' => ModerationStatus::class,
            'moderated_at' => 'datetime',
        ];
    }

    /**
     * Comments a viewer may see: approved comments from active accounts, plus the
     * viewer's own (any review state). The single source of truth for comment
     * visibility — used by the listing, the post's comment_count, and the
     * reply-parent check so they cannot disagree or leak moderation state.
     *
     * @param  Builder<PostComment>  $query
     * @return Builder<PostComment>
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        return $query->where(function (Builder $outer) use ($viewer): void {
            $outer->where(function (Builder $inner): void {
                $inner->where('moderation_status', ModerationStatus::Approved->value)
                    ->whereHas('user', fn (Builder $u) => $u->active());
            });

            if ($viewer !== null) {
                $outer->orWhere('user_id', $viewer->id);
            }
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

    /**
     * @return HasMany<PostComment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(PostComment::class, 'parent_id');
    }
}
