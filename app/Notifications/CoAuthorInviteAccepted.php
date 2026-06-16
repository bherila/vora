<?php

namespace App\Notifications;

use App\Models\StoryAuthor;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CoAuthorInviteAccepted extends Notification
{
    use Queueable;

    public function __construct(private readonly StoryAuthor $storyAuthor) {}

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
        $author = $this->storyAuthor->user;
        $story = $this->storyAuthor->story;

        return [
            'type' => 'co_author_invite_accepted',
            'actor_id' => $this->storyAuthor->user_id,
            'actor_name' => $author?->display_name ?: $author?->name,
            'story_id' => $story?->id,
            'story_ulid' => $story?->ulid,
            'story_title' => $story?->title,
            'url' => '/stories',
        ];
    }
}
