<?php

namespace App\Services\Chat;

use App\Models\ChatParticipant;
use App\Models\User;

/** Invalidates polling clients affected by an account lifecycle transition. */
final class ChatState
{
    public function touchUserAndPeers(User $user): void
    {
        $conversationIds = ChatParticipant::query()
            ->where('user_id', $user->id)
            ->select('conversation_id');
        $userIds = ChatParticipant::query()
            ->whereIn('conversation_id', $conversationIds)
            ->pluck('user_id')
            ->push($user->id)
            ->unique()
            ->values();

        User::withTrashed()->whereIn('id', $userIds)->increment('chat_sync_version');
    }
}
