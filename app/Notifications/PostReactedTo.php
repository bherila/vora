<?php

namespace App\Notifications;

use App\Models\Post;
use App\Models\User;
use App\Notifications\Concerns\DeliversWebPush;
use App\Notifications\Concerns\HasDatabaseActor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent to a post's author when someone reacts to it.
 */
class PostReactedTo extends Notification implements ShouldQueue
{
    use DeliversWebPush;
    use HasDatabaseActor;
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
        return $this->deliveryChannels($notifiable, 'notify_post_reaction');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'post_reaction',
            'actor_id' => $this->actor->id,
            'actor_name' => $this->actor->display_name ?: $this->actor->name,
            'post_id' => $this->post->id,
            'post_ulid' => $this->post->ulid,
            'url' => '/p/'.$this->post->ulid,
        ];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->withDatabaseActor($this->toArray($notifiable), $this->actor->id);
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $data = $this->toArray($notifiable);
        $actor = (string) ($data['actor_name'] ?? 'Someone');

        return $this->webPushMessage('New reaction', $actor.' reacted to your post.', $data);
    }
}
