<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recipient of one sent email, and the latest outcome its provider reported.
 */
class MailDelivery extends Model
{
    use MassPrunable;

    public const RETENTION_DAYS = 90;

    /**
     * Outcomes only move forward: a late "deferred" never hides "delivered", and a
     * bounce or complaint after delivery still wins.
     *
     * @var array<string, int>
     */
    public const STATUS_RANK = [
        'sent' => 0,
        'deferred' => 1,
        'delivered' => 2,
        'bounced' => 3,
        'blocked' => 3,
        'failed' => 3,
        'complained' => 4,
    ];

    protected $fillable = ['mailer', 'provider_message_id', 'recipient', 'user_id', 'type', 'status', 'status_reason', 'sent_at', 'status_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'sent_at' => 'datetime', 'status_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return Builder<MailDelivery> */
    public function prunable(): Builder
    {
        return static::query()->where('sent_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
