<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockAuditLog extends Model
{
    public const ACTION_BLOCKED = 'blocked';

    public const ACTION_UNBLOCKED = 'unblocked';

    protected $fillable = [
        'block_id',
        'actor_id',
        'blocker_id',
        'blocked_user_id',
        'blocked_character_id',
        'action',
    ];

    /** @return BelongsTo<Block, $this> */
    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }
}
