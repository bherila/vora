<?php

namespace App\Notifications;

use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent to a follower when an account they follow publishes a post they are
 * allowed to see.
 */
class FollowedUserPosted extends Notification
{
    use Queueable;

    public function __construct(private readonly Post $post) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
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
}
