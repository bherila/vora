<?php

namespace App\Notifications;

use App\Models\Post;
use App\Notifications\Concerns\DeliversWebPush;
use App\Notifications\Concerns\HasDatabaseActor;
use App\Notifications\Concerns\SuppressesMutedActor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent to a follower when an account or persona they follow publishes a post
 * they are allowed to see.
 */
class FollowedUserPosted extends Notification implements ShouldQueue
{
    use DeliversWebPush;
    use HasDatabaseActor;
    use Queueable;
    use SuppressesMutedActor;

    public function __construct(private readonly Post $post) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if ($this->actorIsMuted($notifiable, $this->post->user_id, $this->post->character_id)) {
            return [];
        }

        return $this->deliveryChannels($notifiable, 'notify_new_post');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $author = $this->post->user;
        $character = $this->post->character;
        $actorName = match (true) {
            $character !== null => $character->display_name,
            $this->post->character_id === null => $author?->display_name ?: $author?->name,
            default => null,
        };

        $data = [
            'type' => 'new_post',
            'actor_name' => $actorName,
            'post_id' => $this->post->id,
            'post_ulid' => $this->post->ulid,
            'url' => '/p/'.$this->post->ulid,
        ];

        if ($this->post->character_id !== null) {
            $data['actor_character_id'] = $this->post->character_id;
        }

        // Linked personas deliberately disclose their owner relationship, so
        // their notifications retain the account actor id. A Separate persona
        // must never make that correlation machine-readable. If a persona has
        // disappeared before the queued web-push channel runs, fail closed:
        // character_id still tells us this was persona-authored, but not whether
        // public account attribution was safe.
        if ($this->post->character_id === null || $character?->is_linked === true) {
            $data['actor_id'] = $this->post->user_id;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->withDatabaseActor(
            $this->toArray($notifiable),
            $this->post->user_id,
            $this->post->character_id,
        );
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $data = $this->toArray($notifiable);
        $actor = (string) ($data['actor_name'] ?? 'Someone');

        return $this->webPushMessage('New post', $actor.' posted something new.', $data);
    }
}
