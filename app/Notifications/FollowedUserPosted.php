<?php

namespace App\Notifications;

use App\Models\Post;
use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent to a follower when an account they follow publishes a post they are
 * allowed to see.
 */
class FollowedUserPosted extends Notification
{
    use DeliversWebPush;
    use Queueable;

    public function __construct(private readonly Post $post) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->deliveryChannels($notifiable, 'notify_new_post');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $author = $this->post->user;

        return [
            'type' => 'new_post',
            'actor_id' => $this->post->user_id,
            'actor_name' => $author?->display_name ?: $author?->name,
            'post_id' => $this->post->id,
            'post_ulid' => $this->post->ulid,
            'url' => '/p/'.$this->post->ulid,
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $data = $this->toArray($notifiable);
        $actor = (string) ($data['actor_name'] ?? 'Someone');

        return $this->webPushMessage('New post', $actor.' posted something new.', $data);
    }
}
