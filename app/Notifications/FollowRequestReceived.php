<?php

namespace App\Notifications;

use App\Models\FollowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FollowRequestReceived extends Notification
{
    use Queueable;

    public function __construct(private readonly FollowRequest $followRequest) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $requester = $this->followRequest->requester;

        return (new MailMessage)
            ->subject('New follow request')
            ->greeting('You have a new follow request')
            ->line(($requester?->display_name ?: $requester?->name ?: 'Someone').' wants to follow you.')
            ->action('Review follow requests', url('/users/follow-requests'));
    }
}
