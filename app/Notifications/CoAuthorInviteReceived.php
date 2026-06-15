<?php

namespace App\Notifications;

use App\Models\StoryAuthor;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CoAuthorInviteReceived extends Notification
{
    use Queueable;

    public function __construct(private readonly StoryAuthor $storyAuthor) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $inviter = $this->storyAuthor->invitedBy;
        $story = $this->storyAuthor->story;

        return (new MailMessage)
            ->subject('Co-author invitation')
            ->greeting('You have been invited to co-author a story')
            ->line(($inviter?->display_name ?: $inviter?->name ?: 'Someone')
                .' invited you to co-author "'.($story?->title ?? 'a story').'".')
            ->action('Review invitations', url('/users/follow-requests'));
    }
}
