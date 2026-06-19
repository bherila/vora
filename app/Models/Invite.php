<?php

namespace App\Models;

use App\Services\InviteService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invite extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'inviter_user_id',
        'invite_grant_id',
        'expires_at',
        'used_at',
        'invited_user_id',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Public links use the opaque uuid, never the auto-increment id.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The user who generated (owns) this invite link.
     *
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }

    /**
     * The account that signed up using this invite (null until consumed).
     *
     * @return BelongsTo<User, $this>
     */
    public function invitedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_user_id');
    }

    /**
     * @return BelongsTo<InviteGrant, $this>
     */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(InviteGrant::class, 'invite_grant_id');
    }

    /**
     * Whether this invite can still be redeemed: not used, not revoked, and not
     * past its expiry. Does not consider the inviter's account status — see
     * {@see InviteService::findUsable()} for the full check.
     */
    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
