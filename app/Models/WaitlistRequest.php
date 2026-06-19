<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaitlistRequest extends Model
{
    use HasFactory;

    /**
     * Verification secrets and admit bookkeeping are set explicitly by the
     * service/controller, never mass-assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'email',
        'birth_date',
        'interests',
        'ip_address',
        'geo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'geo' => 'array',
            'verified_at' => 'datetime',
            'admitted_at' => 'datetime',
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
     * The admin who admitted this request (null until admitted).
     *
     * @return BelongsTo<User, $this>
     */
    public function admittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admitted_by_user_id');
    }

    /**
     * The invite minted when this request was admitted (null until admitted).
     *
     * @return BelongsTo<Invite, $this>
     */
    public function invite(): BelongsTo
    {
        return $this->belongsTo(Invite::class, 'invite_id');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isAdmitted(): bool
    {
        return $this->admitted_at !== null;
    }
}
