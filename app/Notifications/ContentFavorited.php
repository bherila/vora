<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\DeliversWebPush;
use App\Notifications\Concerns\HasDatabaseActor;
use App\Notifications\Concerns\SuppressesMutedActor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent to a content owner when someone saves (favorites) their item. The label
 * and url are resolved by the caller (via FavoriteService::present) so this
 * notification carries no model and stays consistent with the favorites cards.
 */
class ContentFavorited extends Notification implements ShouldQueue
{
    use DeliversWebPush;
    use HasDatabaseActor;
    use Queueable;
    use SuppressesMutedActor;

    public function __construct(
        private readonly User $actor,
        private readonly string $itemType,
        private readonly string $itemLabel,
        private readonly string $itemUrl,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if ($this->actorIsMuted($notifiable, $this->actor->id)) {
            return [];
        }

        return $this->deliveryChannels($notifiable, 'notify_favorite');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'favorite',
            'actor_id' => $this->actor->id,
            'actor_name' => $this->actor->display_name ?: $this->actor->name,
            'item_type' => $this->itemType,
            'item_label' => $this->itemLabel,
            'url' => $this->itemUrl,
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

        return $this->webPushMessage('New save', $actor.' saved your '.$this->itemType.'.', $data);
    }
}
