<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** Best-effort browser wakeup. Durable chat state never depends on this. */
final class ChatMessageWakeup extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $conversationUlid,
        private readonly string $senderPublicUlid,
        private readonly string $senderPublicName,
    ) {
        $this->onConnection('database')->onQueue('chat-notifications');
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'chat_message',
            'actor_id' => $this->senderPublicUlid,
            'actor_name' => $this->senderPublicName,
            'conversation_id' => $this->conversationUlid,
            'url' => "/messages/{$this->conversationUlid}",
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $data = $this->toArray($notifiable);

        return (new WebPushMessage)
            ->title('New private message')
            ->body($this->senderPublicName.' sent you a message.')
            ->icon('/favicon.ico')
            ->data([
                'url' => $data['url'],
                'type' => $data['type'],
            ]);
    }
}
