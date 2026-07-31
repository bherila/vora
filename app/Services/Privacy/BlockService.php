<?php

namespace App\Services\Privacy;

use App\Models\Block;
use App\Models\BlockAuditLog;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\FollowRequestAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BlockService
{
    public function block(User $blocker, User $blocked, ?Character $character = null): Block
    {
        if ($blocker->is($blocked) || ($character instanceof Character && $character->user_id !== $blocked->id)) {
            throw new InvalidArgumentException('A block must target another user and a persona they own.');
        }

        return DB::transaction(function () use ($blocker, $blocked, $character): Block {
            $block = Block::query()->firstOrCreate([
                'blocker_id' => $blocker->id,
                'blocked_user_id' => $blocked->id,
                'blocked_character_id' => $character?->id,
            ]);

            if ($block->wasRecentlyCreated) {
                $this->auditBlock($block, BlockAuditLog::ACTION_BLOCKED);
                $this->severFollows($block);
                $this->touchChatSync($block->blocker_id, $block->blocked_user_id);
            }

            return $block;
        });
    }

    public function unblock(User $blocker, User $blocked, ?Character $character = null): bool
    {
        return DB::transaction(function () use ($blocker, $blocked, $character): bool {
            $block = Block::query()
                ->where('blocker_id', $blocker->id)
                ->where('blocked_user_id', $blocked->id)
                ->where('blocked_character_id', $character?->id)
                ->first();

            if (! $block instanceof Block) {
                return false;
            }

            $this->auditBlock($block, BlockAuditLog::ACTION_UNBLOCKED);
            $block->delete();
            $this->touchChatSync($block->blocker_id, $block->blocked_user_id);

            return true;
        });
    }

    public function remove(Block $block): void
    {
        DB::transaction(function () use ($block): void {
            $this->auditBlock($block, BlockAuditLog::ACTION_UNBLOCKED);
            $block->delete();
            $this->touchChatSync($block->blocker_id, $block->blocked_user_id);
        });
    }

    private function severFollows(Block $block): void
    {
        // Denial removes every follow the blocked account has toward the
        // blocker. The blocked account already knows its own identity set.
        $blockedOutbound = FollowRequest::query()
            ->where('requester_id', $block->blocked_user_id)
            ->where('recipient_id', $block->blocker_id)
            ->get();

        // The blocker can observe their own following list, so remove only the
        // identity they explicitly selected. Cascading this side to a Separate
        // persona would reveal its owner association.
        $blockerOutbound = FollowRequest::query()
            ->where('requester_id', $block->blocker_id)
            ->where('recipient_id', $block->blocked_user_id)
            ->where('recipient_character_id', $block->blocked_character_id)
            ->get();

        $blockedOutbound
            ->merge($blockerOutbound)
            ->unique('id')
            ->each(function (FollowRequest $follow) use ($block): void {
                FollowRequestAuditLog::query()->create([
                    'follow_request_id' => $follow->id,
                    'actor_id' => $block->blocker_id,
                    'requester_id' => $follow->requester_id,
                    'recipient_id' => $follow->recipient_id,
                    'action' => 'removed_by_block',
                    'metadata' => [
                        'recipient_character_id' => $follow->recipient_character_id,
                        'block_id' => $block->id,
                    ],
                ]);
                $follow->delete();
            });
    }

    private function auditBlock(Block $block, string $action): void
    {
        BlockAuditLog::query()->create([
            'block_id' => $block->id,
            'actor_id' => $block->blocker_id,
            'blocker_id' => $block->blocker_id,
            'blocked_user_id' => $block->blocked_user_id,
            'blocked_character_id' => $block->blocked_character_id,
            'action' => $action,
        ]);
    }

    private function touchChatSync(int $firstUserId, int $secondUserId): void
    {
        User::query()
            ->whereIn('id', [$firstUserId, $secondUserId])
            ->increment('chat_sync_version');
    }
}
