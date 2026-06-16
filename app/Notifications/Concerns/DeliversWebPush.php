<?php

namespace App\Notifications\Concerns;

use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

trait DeliversWebPush
{
    /**
     * @return array<int, string>
     */
    protected function deliveryChannels(object $notifiable, ?string $preference = null): array
    {
        if ($preference !== null && ! (bool) ($notifiable->{$preference} ?? true)) {
            return [];
        }

        return ['database', WebPushChannel::class];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function webPushMessage(string $title, string $body, array $data): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($title)
            ->body($body)
            ->icon('/favicon.ico')
            ->data([
                'url' => $data['url'] ?? '/',
                'type' => $data['type'] ?? null,
            ]);
    }
}
