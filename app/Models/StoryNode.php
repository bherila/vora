<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single passage of a choose-your-own-adventure {@see Story}.
 */
class StoryNode extends Model
{
    use HasFactory;
    use SerializesDatesAsLocal;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'story_id',
        'key',
        'title',
        'body',
        'is_start',
        'position_x',
        'position_y',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_start' => 'boolean',
            'position_x' => 'float',
            'position_y' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Story, $this>
     */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /**
     * Choices that originate from this node.
     *
     * @return HasMany<StoryChoice, $this>
     */
    public function choices(): HasMany
    {
        return $this->hasMany(StoryChoice::class, 'from_node_id');
    }
}
