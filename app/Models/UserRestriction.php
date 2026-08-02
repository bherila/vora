<?php

namespace App\Models;

use App\Enums\RestrictionCapability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRestriction extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'capability',
        'restricted_by_user_id',
        'reason',
        'expires_at',
        'lifted_at',
        'lifted_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'capability' => RestrictionCapability::class,
            'expires_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    /**
     * Expiry is evaluated on every read. There is intentionally no scheduled
     * expiry task, which keeps this reliable on shared hosting.
     *
     * @param  Builder<UserRestriction>  $query
     * @return Builder<UserRestriction>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('lifted_at')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function isActive(): bool
    {
        return $this->lifted_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function restrictedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restricted_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function liftedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifted_by_user_id');
    }
}
