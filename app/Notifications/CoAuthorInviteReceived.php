<?php

namespace App\Notifications;

use App\Models\StoryAuthor;
use App\Notifications\Concerns\DeliversWebPush;
use App\Notifications\Concerns\HasDatabaseActor;
use App\Notifications\Concerns\SuppressesMutedActor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class CoAuthorInviteReceived extends Notification implements ShouldQueue
{
    use DeliversWebPush;
    use HasDatabaseActor;
    use Queueable;
    use SuppressesMutedActor;

    public function __construct(private readonly StoryAuthor $storyAuthor) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        if ($this->actorIsMuted($notifiable, $this->storyAuthor->invited_by_user_id)) {
            return [];
        }

        return $this->deliveryChannels($notifiable, 'notify_co_author_invite');
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

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->withDatabaseActor($this->toArray($notifiable), $this->storyAuthor->invited_by_user_id);
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $data = $this->toArray($notifiable);
        $actor = (string) ($data['actor_name'] ?? 'Someone');

        return $this->webPushMessage('Co-author invitation', $actor.' invited you to co-author a story.', $data);
    }
}
