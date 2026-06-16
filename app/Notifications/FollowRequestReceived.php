<?php

namespace App\Notifications;

use App\Models\FollowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class FollowRequestReceived extends Notification
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
        $requester = $this->followRequest->requester;

        return [
            'type' => 'follow_request',
            'actor_id' => $this->followRequest->requester_id,
            'actor_name' => $requester?->display_name ?: $requester?->name,
            'follow_request_id' => $this->followRequest->id,
            'url' => '/users/follow-requests',
        ];
    }
}
