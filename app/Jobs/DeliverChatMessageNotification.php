<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Notifications\ChatMessageWakeup;
use App\Services\Chat\ChatGate;
use App\Support\MuteGraph;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Re-check and coalesce an offline wakeup after a message is committed.
 * The job carries only an opaque message key and is safe to retry.
 */
final class DeliverChatMessageNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $messageUlid)
    {
        $this->onConnection('database')->onQueue('chat-notifications');
    }

    public function handle(ChatGate $gate): void
    {
        $message = ChatMessage::query()
            ->with(['conversation.participants.user', 'sender'])
            ->where('ulid', $this->messageUlid)
            ->first();
        if (! $message instanceof ChatMessage || ! $message->sender instanceof User) {
            return;
        }

        $recipientParticipant = $message->conversation->participants
            ->first(fn (ChatParticipant $participant): bool => $participant->user_id !== $message->sender_user_id);
        $recipient = $recipientParticipant?->user;

        if (! $recipient instanceof User
            || ! $recipient->notify_message
            || ! $recipient->isActive()
            || $recipient->isBanned()
            || ! $message->sender->isActive()
            || $message->sender->isBanned()
            || $recipientParticipant->unread_count < 1
            || ($recipientParticipant->last_read_message_id !== null
                && $recipientParticipant->last_read_message_id >= $message->id)
            || ! $gate->mayRead($recipient, $message->conversation)
            || MuteGraph::isMutedIdentity($recipient->id, $message->sender_user_id, null)) {
            return;
        }

        $payload = [
            'type' => 'chat_message',
            'actor_id' => $message->sender_public_ulid,
            'actor_name' => $message->sender_public_name,
            'conversation_id' => $message->conversation->ulid,
            'url' => "/messages/{$message->conversation->ulid}",
            '_actor_user_id' => $message->sender_user_id,
            '_actor_character_id' => null,
        ];

        $existing = DB::table('notifications')
            ->where('type', ChatMessageWakeup::class)
            ->where('notifiable_type', $recipient->getMorphClass())
            ->where('notifiable_id', $recipient->id)
            ->whereNull('read_at')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->where('data->conversation_id', $message->conversation->ulid)
            ->first();

        if ($existing !== null) {
            DB::table('notifications')->where('id', $existing->id)->update([
                'data' => json_encode($payload, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => ChatMessageWakeup::class,
            'notifiable_type' => $recipient->getMorphClass(),
            'notifiable_id' => $recipient->id,
            'data' => json_encode($payload, JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Queue the outbound push only for the first row in the coalescing
        // window. A retry sees that row and cannot enqueue a duplicate push.
        $recipient->notify(new ChatMessageWakeup(
            $message->conversation->ulid,
            $message->sender_public_ulid,
            $message->sender_public_name,
        ));
    }
}
