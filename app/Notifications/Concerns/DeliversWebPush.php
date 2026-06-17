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

        // The database write is synchronous and fast; web push requires an
        // outbound HTTP call to the browser push service. Separating the channels
        // lets 'database' run inline while web push is dispatched asynchronously
        // to the queue so a slow or unreachable push service cannot delay or fail
        // the caller's request.
        return ['database', WebPushChannel::class];
    }

    /**
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return [
            'database' => 'sync',
            WebPushChannel::class => (string) config('queue.default', 'sync'),
        ];
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
