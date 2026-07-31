<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;

final class ChatInbox
{
    public function __construct(private readonly ChatGate $gate) {}

    public function unreadCount(User $viewer): int
    {
        $visibleConversationIds = $this->gate
            ->constrainVisibleConversations(ChatConversation::query(), $viewer)
            ->select('chat_conversations.id');

        return (int) ChatParticipant::query()
            ->where('user_id', $viewer->id)
            ->whereIn('conversation_id', $visibleConversationIds)
            ->sum('unread_count');
    }
}
