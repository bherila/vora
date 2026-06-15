<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A "involves" tag on a {@see Story}: a polymorphic link to a {@see User} or a
 * {@see Character} that the story features.
 */
class StoryInvolvement extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'story_id',
        'involvable_type',
        'involvable_id',
    ];

    /**
     * @return BelongsTo<Story, $this>
     */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function involvable(): MorphTo
    {
        return $this->morphTo();
    }
}
