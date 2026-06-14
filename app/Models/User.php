<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory;
    use Notifiable;

    /**
     * Mass-assignable attributes. Privilege/approval columns are intentionally
     * excluded — they are set only through admin flows, never from registration input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'gender',
        'gender_other',
        'last_login_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'approved_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_disabled' => 'boolean',
            'force_change_pw' => 'boolean',
        ];
    }

    /**
     * User id 1 is always an admin; otherwise the explicit flag governs.
     */
    public function isAdmin(): bool
    {
        return $this->id === 1 || $this->is_admin === true;
    }

    /**
     * Approved by an admin (or grandfathered via approved_at being set).
     */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * Registered but not yet approved (and not disabled).
     */
    public function isPendingApproval(): bool
    {
        return $this->approved_at === null && ! $this->is_disabled;
    }

    /**
     * Hard-disabled / rejected accounts cannot authenticate at all.
     */
    public function canLogin(): bool
    {
        return ! $this->is_disabled;
    }

    /**
     * Where an approved user lands after authenticating.
     */
    public function getLoginRedirectUrl(): string
    {
        return '/';
    }

    /**
     * The admin who approved this account.
     *
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return HasMany<InterestRating, $this>
     */
    public function interestRatings(): HasMany
    {
        return $this->hasMany(InterestRating::class);
    }

    /**
     * @return HasMany<InterestRequest, $this>
     */
    public function interestRequests(): HasMany
    {
        return $this->hasMany(InterestRequest::class);
    }
}
