<?php

namespace App\Notifications;

use App\Models\Post;
use App\Models\User;
use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent to a post's author when someone comments on it.
 */
class PostCommentedOn extends Notification
{
    use DeliversWebPush;
    use Queueable;

    public function __construct(
        private readonly Post $post,
        private readonly User $actor,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->deliveryChannels($notifiable, 'notify_post_comment');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'post_comment',
            'actor_id' => $this->actor->id,
            'actor_name' => $this->actor->display_name ?: $this->actor->name,
            'post_id' => $this->post->id,
            'post_ulid' => $this->post->ulid,
            'url' => '/p/'.$this->post->ulid,
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $data = $this->toArray($notifiable);
        $actor = (string) ($data['actor_name'] ?? 'Someone');

        return $this->webPushMessage('New comment', $actor.' commented on your post.', $data);
    }
}
