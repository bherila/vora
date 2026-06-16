<?php

namespace App\Notifications;

use App\Models\FollowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class FollowRequestAccepted extends Notification
{
    use Queueable;

    public function __construct(private readonly FollowRequest $followRequest) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
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
}
