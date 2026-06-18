<?php

namespace App\Notifications;

use App\Models\StoryAuthor;
use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class CoAuthorInviteAccepted extends Notification implements ShouldQueue
{
    use DeliversWebPush;
    use Queueable;

    public function __construct(private readonly StoryAuthor $storyAuthor) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->deliveryChannels($notifiable, 'notify_co_author_invite_accepted');
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

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $data = $this->toArray($notifiable);
        $actor = (string) ($data['actor_name'] ?? 'Someone');

        return $this->webPushMessage('Co-author invitation accepted', $actor.' accepted your co-author invitation.', $data);
    }
}
