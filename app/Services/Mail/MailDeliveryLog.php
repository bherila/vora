<?php

namespace App\Services\Mail;

use App\Models\MailDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Message;
use Throwable;

/**
 * Records every sent email and applies the delivery outcomes providers report.
 * Provider-neutral: webhook adapters normalize their events before calling apply().
 */
class MailDeliveryLog
{
    /**
     * Never let bookkeeping fail a send that already happened.
     *
     * @param  array<string, mixed>  $data  The view data Laravel passes with MessageSent.
     */
    public function record(SentMessage $sent, array $data): void
    {
        try {
            $original = $sent->getOriginalMessage();
            // Laravel's SES transports expose SES's own id only as a header.
            $sesId = $original instanceof Message ? $original->getHeaders()->get('X-SES-Message-ID')?->getBodyAsString() : null;
            $messageId = self::normalizeMessageId($sesId ?? $sent->getMessageId());
            $class = $data['__laravel_notification'] ?? $data['__laravel_mailable'] ?? null;
            $now = now();
            $recipients = $sent->getEnvelope()->getRecipients();
        } catch (Throwable $exception) {
            report($exception);

            return;
        }
        // One recipient's failure must not leave the message's others unrecorded.
        foreach ($recipients as $address) {
            try {
                $recipient = mb_substr(mb_strtolower($address->getAddress()), 0, 254);
                MailDelivery::create([
                    // The configured mailer; under a failover chain this does not say which transport sent it.
                    'mailer' => (string) config('mail.default'),
                    'provider_message_id' => $messageId,
                    'recipient' => $recipient,
                    // Stored addresses keep their original case; match either form on every database.
                    'user_id' => User::query()->whereIn('email', array_unique([$address->getAddress(), $recipient]))->value('id'),
                    'type' => is_string($class) ? mb_substr(class_basename($class), 0, 100) : null,
                    'status' => 'sent',
                    'sent_at' => $now,
                    'status_at' => $now,
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * Apply one normalized provider event. A null recipient applies to every
     * recipient of the message (for example an SES rejection). Returns false for
     * messages this app never sent, which shared provider accounts will deliver.
     */
    public function apply(string $messageId, ?string $recipient, string $status, ?string $reason, CarbonImmutable $at): bool
    {
        if (! isset(MailDelivery::STATUS_RANK[$status])) {
            return false;
        }

        return DB::transaction(function () use ($messageId, $recipient, $status, $reason, $at): bool {
            $query = MailDelivery::query()->where('provider_message_id', self::normalizeMessageId($messageId));
            if ($recipient !== null) {
                $query->where('recipient', mb_strtolower(trim($recipient)));
            }
            $deliveries = $query->lockForUpdate()->get();
            foreach ($deliveries as $delivery) {
                $current = MailDelivery::STATUS_RANK[$delivery->status] ?? 0;
                $next = MailDelivery::STATUS_RANK[$status];
                if ($next < $current || ($next === $current && $delivery->status_at !== null && $at->lt($delivery->status_at))) {
                    continue;
                }
                $delivery->forceFill([
                    'status' => $status,
                    'status_reason' => $reason === null ? null : mb_substr(trim($reason), 0, 255),
                    'status_at' => $at,
                ])->save();
            }

            return $deliveries->isNotEmpty();
        });
    }

    public static function normalizeMessageId(string $messageId): string
    {
        return mb_substr(trim($messageId, " \t\n\r\0\x0B<>"), 0, 255);
    }
}
