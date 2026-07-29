<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A polymorphic attachment on a {@see Post}: a link to a Character, Interest,
 * Media, or Story the author owns (Interest is a shared tag). Mirrors the
 * {@see StoryInvolvement} pattern.
 */
class PostAttachment extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'post_id',
        'attachable_type',
        'attachable_id',
    ];

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo()->morphWith([
            Media::class => ['character:id,is_linked'],
            Story::class => ['authors.character:id,is_linked'],
        ]);
    }
}
