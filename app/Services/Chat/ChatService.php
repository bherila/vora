<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ChatService
{
    public function __construct(private readonly ChatGate $gate) {}

    public function conversationBetween(User $sender, User $recipient): ChatConversation
    {
        if (! $this->gate->mayCreateOrSend($sender, $recipient)) {
            throw new DomainException('Messaging is unavailable.');
        }

        [$lowerUserId, $higherUserId] = $this->orderedPair($sender->id, $recipient->id);

        $existing = $this->findPair($lowerUserId, $higherUserId);
        if ($existing instanceof ChatConversation) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($lowerUserId, $higherUserId): ChatConversation {
                $conversation = ChatConversation::query()->create([
                    'ulid' => (string) Str::ulid(),
                    'lower_user_id' => $lowerUserId,
                    'higher_user_id' => $higherUserId,
                ]);

                ChatParticipant::query()->insert([
                    [
                        'conversation_id' => $conversation->id,
                        'user_id' => $lowerUserId,
                        'unread_count' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'conversation_id' => $conversation->id,
                        'user_id' => $higherUserId,
                        'unread_count' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ]);

                return $conversation;
            }, 3);
        } catch (QueryException $exception) {
            $conversation = $this->findPair($lowerUserId, $higherUserId);
            if ($conversation instanceof ChatConversation) {
                return $conversation;
            }

            throw $exception;
        }
    }

    public function send(
        User $sender,
        ChatConversation $conversation,
        string $clientMessageId,
        string $body,
    ): ChatMessage {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 5000) {
            throw new DomainException('The message must contain between 1 and 5,000 characters.');
        }

        $otherUserId = $conversation->otherUserId($sender->id);
        $recipient = $otherUserId === null ? null : User::query()->find($otherUserId);

        if (! $recipient instanceof User || ! $this->gate->mayCreateOrSend($sender, $recipient)) {
            throw new DomainException('Messaging is unavailable.');
        }

        try {
            return DB::transaction(function () use ($sender, $conversation, $clientMessageId, $body, $recipient): ChatMessage {
                $lockedConversation = ChatConversation::query()
                    ->whereKey($conversation->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $existing = ChatMessage::query()
                    ->where('sender_user_id', $sender->id)
                    ->where('client_message_id', $clientMessageId)
                    ->first();

                if ($existing instanceof ChatMessage) {
                    return $this->resolveIdempotentMessage($existing, $lockedConversation, $body);
                }

                $sentAt = now();
                $message = ChatMessage::query()->create([
                    'ulid' => (string) Str::ulid(),
                    'conversation_id' => $lockedConversation->id,
                    'sender_user_id' => $sender->id,
                    'client_message_id' => $clientMessageId,
                    'body' => $body,
                    'created_at' => $sentAt,
                    'updated_at' => $sentAt,
                ]);

                $lockedConversation->forceFill(['last_message_at' => $sentAt])->save();

                ChatParticipant::query()
                    ->where('conversation_id', $lockedConversation->id)
                    ->where('user_id', $sender->id)
                    ->update(['last_activity_at' => $sentAt, 'updated_at' => $sentAt]);

                ChatParticipant::query()
                    ->where('conversation_id', $lockedConversation->id)
                    ->where('user_id', $recipient->id)
                    ->update([
                        'unread_count' => DB::raw('unread_count + 1'),
                        'last_activity_at' => $sentAt,
                        'updated_at' => $sentAt,
                    ]);

                User::query()
                    ->whereIn('id', [$sender->id, $recipient->id])
                    ->increment('chat_sync_version');

                return $message;
            }, 3);
        } catch (QueryException $exception) {
            $existing = ChatMessage::query()
                ->where('sender_user_id', $sender->id)
                ->where('client_message_id', $clientMessageId)
                ->first();

            if ($existing instanceof ChatMessage) {
                return $this->resolveIdempotentMessage($existing, $conversation, $body);
            }

            throw $exception;
        }
    }

    public function markRead(User $reader, ChatConversation $conversation, ChatMessage $message): void
    {
        if (! $this->gate->mayRead($reader, $conversation)
            || $message->conversation_id !== $conversation->id) {
            throw new DomainException('Conversation not found.');
        }

        DB::transaction(function () use ($reader, $conversation, $message): void {
            $participant = ChatParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $reader->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($participant->last_read_message_id !== null
                && $participant->last_read_message_id >= $message->id) {
                return;
            }

            $remainingUnread = ChatMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('sender_user_id', '!=', $reader->id)
                ->where('id', '>', $message->id)
                ->count();

            $participant->forceFill([
                'last_read_message_id' => $message->id,
                'unread_count' => $remainingUnread,
            ])->save();

            User::query()->whereKey($reader->id)->increment('chat_sync_version');
        }, 3);
    }

    /** @return array{0: int, 1: int} */
    private function orderedPair(int $firstUserId, int $secondUserId): array
    {
        return $firstUserId < $secondUserId
            ? [$firstUserId, $secondUserId]
            : [$secondUserId, $firstUserId];
    }

    private function findPair(int $lowerUserId, int $higherUserId): ?ChatConversation
    {
        return ChatConversation::query()
            ->where('lower_user_id', $lowerUserId)
            ->where('higher_user_id', $higherUserId)
            ->first();
    }

    private function resolveIdempotentMessage(
        ChatMessage $message,
        ChatConversation $conversation,
        string $body,
    ): ChatMessage {
        if ($message->conversation_id !== $conversation->id || ! hash_equals($message->body, $body)) {
            throw new DomainException('That message key has already been used.');
        }

        return $message;
    }
}
