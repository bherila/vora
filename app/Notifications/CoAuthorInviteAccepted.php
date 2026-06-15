<?php

namespace App\Notifications;

use App\Models\StoryAuthor;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CoAuthorInviteAccepted extends Notification
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
        $author = $this->storyAuthor->user;
        $story = $this->storyAuthor->story;

        return (new MailMessage)
            ->subject('Co-author invitation accepted')
            ->greeting('Your co-author invitation was accepted')
            ->line(($author?->display_name ?: $author?->name ?: 'Someone')
                .' accepted your invitation to co-author "'.($story?->title ?? 'a story').'".')
            ->action('Open story', url('/stories'));
    }
}
