<?php

namespace App\Notifications;

use App\Models\StoryAuthor;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CoAuthorInviteReceived extends Notification
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
        $inviter = $this->storyAuthor->invitedBy;
        $story = $this->storyAuthor->story;

        return [
            'type' => 'co_author_invite',
            'actor_id' => $this->storyAuthor->invited_by_user_id,
            'actor_name' => $inviter?->display_name ?: $inviter?->name,
            'story_id' => $story?->id,
            'story_ulid' => $story?->ulid,
            'story_title' => $story?->title,
            'url' => '/users/follow-requests',
        ];
    }
}
