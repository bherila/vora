<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FollowRequest extends Model
{
    use HasFactory;

    /** A follow that has been requested but not yet answered. */
    public const STATUS_PENDING = 'pending';

    /** An accepted follow — requester now follows recipient. */
    public const STATUS_ACCEPTED = 'accepted';

    /** A follow the recipient turned down. */
    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'requester_id',
        'recipient_id',
        'recipient_character_id',
        'status',
        'responded_at',
    ];

    protected function casts(): array
    {
        return ['responded_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /** @return BelongsTo<Character, $this> */
    public function recipientCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'recipient_character_id');
    }

    /** @return HasMany<FollowRequestAuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(FollowRequestAuditLog::class);
    }
}
