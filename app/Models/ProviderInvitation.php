<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An admission invitation the identity provider sent for this installation.
 *
 * @property int $id
 * @property string $oauth_provider
 * @property int $provider_invitation_id
 * @property int|null $inviter_user_id
 * @property int|null $invite_grant_id
 * @property string $email
 * @property string $status
 * @property Carbon $expires_at
 * @property bool $email_sent
 */
class ProviderInvitation extends Model
{
    public const PENDING = 'pending';

    public const REVOKED = 'revoked';

    /** A later invitation to the same address replaced it at the provider. */
    public const SUPERSEDED = 'superseded';

    public const ACCEPTED = 'accepted';

    /** The provider no longer treats it as pending, for a reason it did not say. */
    public const CLOSED = 'closed';

    /** @var list<string> */
    protected $fillable = [
        'oauth_provider',
        'provider_invitation_id',
        'inviter_user_id',
        'invite_grant_id',
        'email',
        'status',
        'expires_at',
        'email_sent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider_invitation_id' => 'integer',
            'expires_at' => 'datetime',
            'email_sent' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }

    /**
     * Pending invitations to an address, which the provider matches without regard to case.
     *
     * @param  Builder<ProviderInvitation>  $query
     * @return Builder<ProviderInvitation>
     */
    public function scopePendingTo(Builder $query, string $provider, string $email): Builder
    {
        return $query->where('oauth_provider', $provider)
            ->where('status', self::PENDING)
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)]);
    }
}
