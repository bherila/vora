<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class ChatInbox
{
    public function __construct(private readonly ChatGate $gate) {}

    public function unreadCount(User $viewer): int
    {
        $visibleConversationIds = $this->gate
            ->constrainVisibleConversations(ChatConversation::query(), $viewer)
            // A human-account mute suppresses the global badge, but not the
            // explicit thread's own unread state or retained history.
            ->whereExists(function (QueryBuilder $other) use ($viewer): void {
                $other->selectRaw('1')
                    ->from('chat_participants as badge_other_participants')
                    ->whereColumn('badge_other_participants.conversation_id', 'chat_conversations.id')
                    ->where('badge_other_participants.user_id', '!=', $viewer->id)
                    ->whereNotExists(function (QueryBuilder $mutes) use ($viewer): void {
                        $mutes->selectRaw('1')
                            ->from('mutes')
                            ->where('mutes.user_id', $viewer->id)
                            ->whereColumn('mutes.muted_user_id', 'badge_other_participants.user_id')
                            ->whereNull('mutes.muted_character_id');
                    });
            })
            ->select('chat_conversations.id');

        return (int) ChatParticipant::query()
            ->where('user_id', $viewer->id)
            ->whereIn('conversation_id', $visibleConversationIds)
            ->sum('unread_count');
    }
}
