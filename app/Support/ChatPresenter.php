<?php

namespace App\Support;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Services\Chat\ChatGate;
use App\Services\Media\MediaResponseService;

final class ChatPresenter
{
    public function __construct(
        private readonly ChatGate $gate,
        private readonly MediaResponseService $mediaResponder,
    ) {}

    /** @return array<string, mixed> */
    public function conversation(ChatConversation $conversation, User $viewer): array
    {
        $participant = $conversation->participants
            ->first(fn (ChatParticipant $item): bool => $item->user_id === $viewer->id);
        $other = $conversation->participants
            ->first(fn (ChatParticipant $item): bool => $item->user_id !== $viewer->id)
            ?->user;

        return [
            'id' => $conversation->ulid,
            'other_user' => $other instanceof User ? [
                'id' => $other->public_ulid,
                'display_name' => $other->display_name ?: $other->name,
                'avatar_url' => UserPresenter::avatarUrl($other, $this->mediaResponder, $viewer),
            ] : null,
            'latest_message' => $conversation->latestMessage instanceof ChatMessage
                ? $this->message($conversation->latestMessage, $viewer)
                : null,
            'unread_count' => $participant?->unread_count ?? 0,
            'may_send' => $other instanceof User && $this->gate->mayCreateOrSend($viewer, $other),
            'last_activity_at' => $participant?->last_activity_at?->toIso8601String()
                ?? $conversation->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function message(ChatMessage $message, User $viewer): array
    {
        $isMine = $message->sender_user_id === $viewer->id;

        return [
            'id' => $message->ulid,
            'sender_id' => $message->sender?->public_ulid,
            'body' => $message->body,
            'created_at' => $message->created_at?->toIso8601String(),
            'is_mine' => $isMine,
            'client_message_id' => $isMine ? $message->client_message_id : null,
        ];
    }
}
