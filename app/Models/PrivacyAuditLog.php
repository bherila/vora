<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only audit trail of privacy-policy changes on any content item, for
 * SOC2-style attribution: who changed which item's audience / discoverability /
 * allowlist, from what to what, when, and from where.
 *
 * The actor is null-on-delete: erasing a user removes the PII linkage while the
 * compliance record itself is retained (GDPR/CCPA right-to-erasure reconciled
 * with audit retention). Records are never updated or deleted in normal flow.
 */
class PrivacyAuditLog extends Model
{
    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    protected $fillable = [
        'privacyable_type',
        'privacyable_id',
        'user_id',
        'action',
        'old_audience',
        'new_audience',
        'old_discoverable',
        'new_discoverable',
        'added_user_ids',
        'removed_user_ids',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_discoverable' => 'boolean',
            'new_discoverable' => 'boolean',
            'added_user_ids' => 'array',
            'removed_user_ids' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function privacyable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The user who made the change. Null once that account is erased.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
