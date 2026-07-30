<?php

namespace App\Notifications;

use App\Models\FollowRequest;
use App\Notifications\Concerns\DeliversWebPush;
use App\Notifications\Concerns\SuppressesMutedActor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class FollowRequestReceived extends Notification implements ShouldQueue
{
    use DeliversWebPush;
    use Queueable;
    use SuppressesMutedActor;

    public function __construct(private readonly FollowRequest $followRequest) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        if ($this->actorIsMuted($notifiable, $this->followRequest->requester_id)) {
            return [];
        }

        return $this->deliveryChannels($notifiable, 'notify_follow_request');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $requester = $this->followRequest->requester;

        return [
            'type' => 'follow_request',
            'actor_id' => $this->followRequest->requester_id,
            'actor_name' => $requester?->display_name ?: $requester?->name,
            'follow_request_id' => $this->followRequest->id,
            'url' => '/users/follow-requests',
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $data = $this->toArray($notifiable);
        $actor = (string) ($data['actor_name'] ?? 'Someone');

        return $this->webPushMessage('New follow request', $actor.' wants to follow you.', $data);
    }
}
