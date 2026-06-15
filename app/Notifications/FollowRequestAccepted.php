<?php

namespace App\Notifications;

use App\Models\FollowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FollowRequestAccepted extends Notification
{
    use Queueable;

    public function __construct(private readonly FollowRequest $followRequest) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $recipient = $this->followRequest->recipient;

        return (new MailMessage)
            ->subject('Follow request accepted')
            ->greeting('Your follow request was accepted')
            ->line(($recipient?->display_name ?: $recipient?->name ?: 'A user').' accepted your follow request.')
            ->action('View profile', url('/users/'.$this->followRequest->recipient_id));
    }
}
