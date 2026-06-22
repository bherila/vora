<?php

namespace App\Notifications;

use App\Models\Report;
use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent to admins when a new abuse report is filed, so the review queue isn't
 * something they have to remember to check.
 */
class AbuseReportFiled extends Notification implements ShouldQueue
{
    use DeliversWebPush;
    use Queueable;

    public function __construct(private readonly Report $report) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        // No per-admin preference key — admins always get the review signal.
        return $this->deliveryChannels($notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'abuse_report',
            'report_id' => $this->report->id,
            'reason' => $this->report->reason->value,
            'url' => '/admin/reports',
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->webPushMessage('New abuse report', 'A new report is waiting for review.', $this->toArray($notifiable));
    }
}
