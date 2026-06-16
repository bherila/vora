<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single reaction by one user on one post. The `type` is kept open (one
 * "like" today) so the reaction set can grow without a migration.
 */
class PostReaction extends Model
{
    use HasFactory;

    /** Reaction types currently offered. */
    public const TYPES = ['like'];

    public const DEFAULT_TYPE = 'like';

    protected $fillable = ['user_id', 'post_id', 'type'];

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
}
