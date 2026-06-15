<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowRequestAuditLog extends Model
{
    protected $fillable = ['follow_request_id', 'actor_id', 'requester_id', 'recipient_id', 'action', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return BelongsTo<FollowRequest, $this> */
    public function followRequest(): BelongsTo { return $this->belongsTo(FollowRequest::class); }
}
