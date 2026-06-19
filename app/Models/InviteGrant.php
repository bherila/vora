<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InviteGrant extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'quantity',
        'remaining',
        'expires_at',
        'issued_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'remaining' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The user who holds this grant (can generate invites from it).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The admin who issued this grant.
     *
     * @return BelongsTo<User, $this>
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    /**
     * @return HasMany<Invite, $this>
     */
    public function invites(): HasMany
    {
        return $this->hasMany(Invite::class);
    }

    /**
     * Whether this grant can still be drawn from: has remaining balance and is
     * not past its expiry.
     */
    public function isActive(): bool
    {
        return $this->remaining > 0
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
