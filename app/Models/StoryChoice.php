<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A directed, labelled edge of a CYOA graph: a choice presented on
 * {@see StoryChoice::$from_node_id} that leads to {@see StoryChoice::$to_node_id}
 * (or a terminal ending when the target is null).
 */
class StoryChoice extends Model
{
    use HasFactory;
    use SerializesDatesAsLocal;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'story_id',
        'from_node_id',
        'to_node_id',
        'label',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
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
     * @return BelongsTo<StoryNode, $this>
     */
    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(StoryNode::class, 'from_node_id');
    }

    /**
     * @return BelongsTo<StoryNode, $this>
     */
    public function toNode(): BelongsTo
    {
        return $this->belongsTo(StoryNode::class, 'to_node_id');
    }
}
