<?php

namespace App\Services\Chat;

use App\Models\Block;
use App\Models\ChatConversation;
use App\Models\User;
use App\Support\BlockGraph;
use App\Support\FollowGraph;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The single privacy authority for private conversations.
 *
 * Unlike ordinary content gates, admins receive no bypass. A private thread is
 * visible only to its participants and only while the asymmetric block graph
 * admits the viewer's account identity.
 */
final class ChatGate
{
    public function mayCreateOrSend(User $sender, User $recipient): bool
    {
        if ($sender->is($recipient)
            || ! $sender->isApproved()
            || ! $recipient->isApproved()
            || ! $sender->isActive()
            || ! $recipient->isActive()
            || $sender->isBanned()
            || $recipient->isBanned()
            || ! FollowGraph::mutual($sender->id, $recipient->id)) {
            return false;
        }

        return ! $this->hasBlockBetween($sender->id, $recipient->id);
    }

    public function mayRead(User $viewer, ChatConversation $conversation): bool
    {
        $otherUserId = $conversation->otherUserId($viewer->id);

        if ($otherUserId === null) {
            return false;
        }

        return ! BlockGraph::isDenied($viewer->id, $otherUserId)
            && ! BlockGraph::isHidden($viewer->id, $otherUserId);
    }

    /**
     * Apply participant, denial, and account-identity hide rules in SQL before
     * cursor pagination. Deliberately does not call BlockGraph::visibleTo(),
     * because that helper grants an admin bypass appropriate for public content.
     *
     * @param  Builder<ChatConversation>  $query
     * @return Builder<ChatConversation>
     */
    public function constrainVisibleConversations(Builder $query, User $viewer): Builder
    {
        return $query
            ->whereExists(function (QueryBuilder $participant) use ($viewer): void {
                $participant->selectRaw('1')
                    ->from('chat_participants as viewer_chat_participants')
                    ->whereColumn('viewer_chat_participants.conversation_id', 'chat_conversations.id')
                    ->where('viewer_chat_participants.user_id', $viewer->id);
            })
            ->whereExists(function (QueryBuilder $other) use ($viewer): void {
                $other->selectRaw('1')
                    ->from('chat_participants as other_chat_participants')
                    ->whereColumn('other_chat_participants.conversation_id', 'chat_conversations.id')
                    ->where('other_chat_participants.user_id', '!=', $viewer->id)
                    ->whereNotExists(fn (QueryBuilder $blocks) => BlockGraph::constrainDenied(
                        $blocks,
                        'other_chat_participants.user_id',
                        $viewer->id,
                    ))
                    ->whereNotExists(fn (QueryBuilder $blocks) => BlockGraph::constrainHidden(
                        $blocks,
                        'other_chat_participants.user_id',
                        $viewer->id,
                    ));
            });
    }

    private function hasBlockBetween(int $firstUserId, int $secondUserId): bool
    {
        return Block::query()
            ->where(function (Builder $query) use ($firstUserId, $secondUserId): void {
                $query->where('blocker_id', $firstUserId)
                    ->where('blocked_user_id', $secondUserId);
            })
            ->orWhere(function (Builder $query) use ($firstUserId, $secondUserId): void {
                $query->where('blocker_id', $secondUserId)
                    ->where('blocked_user_id', $firstUserId);
            })
            ->exists();
    }
}
