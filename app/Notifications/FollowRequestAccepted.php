<?php

namespace App\Notifications;

use App\Models\FollowRequest;
use App\Notifications\Concerns\DeliversWebPush;
use App\Notifications\Concerns\HasDatabaseActor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class FollowRequestAccepted extends Notification implements ShouldQueue
{
    use DeliversWebPush;
    use HasDatabaseActor;
    use Queueable;

    public function __construct(private readonly FollowRequest $followRequest) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->deliveryChannels($notifiable, 'notify_follow_accepted');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $accepter = $this->followRequest->recipient;

        return [
            'type' => 'follow_accepted',
            'actor_id' => $this->followRequest->recipient_id,
            'actor_name' => $accepter?->display_name ?: $accepter?->name,
            'url' => '/users/'.$this->followRequest->recipient_id,
        ];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->withDatabaseActor($this->toArray($notifiable), $this->followRequest->recipient_id);
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $data = $this->toArray($notifiable);
        $actor = (string) ($data['actor_name'] ?? 'Someone');

        return $this->webPushMessage('Follow request accepted', $actor.' accepted your follow request.', $data);
    }
}
