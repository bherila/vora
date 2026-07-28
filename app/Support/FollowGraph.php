<?php

namespace App\Support;

use App\Models\Character;
use App\Models\FollowRequest;
use App\Traits\HasPrivacyPolicy;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The single source of truth for reading the follow graph.
 *
 * A follow edge is an accepted {@see FollowRequest}: requester_id follows
 * recipient_id. A NULL recipient_character_id scopes the edge to the whole
 * account. A populated recipient_character_id scopes it to that persona only.
 * Account follows include Linked personas but never Separate personas. Mutuals
 * are account follows in both directions.
 *
 * Every privacy decision that depends on the follow graph — per-record checks
 * in {@see HasPrivacyPolicy::isViewableBy()} and correlated
 * subqueries in scopeViewableBy() — routes through here so the rule lives in one
 * auditable place and cannot drift between the boolean and SQL forms.
 */
final class FollowGraph
{
    /**
     * Whether $followerId follows $followeeId (accepted follow).
     */
    public static function follows(int $followerId, int $followeeId): bool
    {
        return FollowRequest::query()
            ->where('requester_id', $followerId)
            ->where('recipient_id', $followeeId)
            ->whereNull('recipient_character_id')
            ->where('status', FollowRequest::STATUS_ACCEPTED)
            ->exists();
    }

    /**
     * Whether the viewer follows a particular identity owned by the recipient.
     *
     * A persona edge applies only to that persona. An account edge applies to
     * the account identity and its Linked personas, never a Separate persona.
     */
    public static function followsIdentity(
        int $followerId,
        int $recipientId,
        ?int $recipientCharacterId,
    ): bool {
        if ($recipientCharacterId === null) {
            return self::follows($followerId, $recipientId);
        }

        $character = Character::query()
            ->whereKey($recipientCharacterId)
            ->where('user_id', $recipientId)
            ->first(['id', 'is_linked']);

        if ($character === null) {
            return false;
        }

        return FollowRequest::query()
            ->where('requester_id', $followerId)
            ->where('recipient_id', $recipientId)
            ->where('status', FollowRequest::STATUS_ACCEPTED)
            ->where(function (EloquentBuilder $query) use ($character): void {
                $query->where('recipient_character_id', $character->id);

                if ($character->is_linked) {
                    $query->orWhereNull('recipient_character_id');
                }
            })
            ->exists();
    }

    /**
     * Accepted followers of an account or persona identity.
     *
     * @return EloquentBuilder<FollowRequest>
     */
    public static function followersOfIdentity(
        int $recipientId,
        ?int $recipientCharacterId,
    ): EloquentBuilder {
        $query = FollowRequest::query()
            ->where('recipient_id', $recipientId)
            ->where('status', FollowRequest::STATUS_ACCEPTED);

        if ($recipientCharacterId === null) {
            return $query->whereNull('recipient_character_id');
        }

        $character = Character::query()
            ->whereKey($recipientCharacterId)
            ->where('user_id', $recipientId)
            ->first(['id', 'is_linked']);

        if ($character === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (EloquentBuilder $scope) use ($character): void {
            $scope->where('recipient_character_id', $character->id);

            if ($character->is_linked) {
                $scope->orWhereNull('recipient_character_id');
            }
        });
    }

    /**
     * Whether $aId and $bId follow each other.
     */
    public static function mutual(int $aId, int $bId): bool
    {
        return self::follows($aId, $bId) && self::follows($bId, $aId);
    }

    /**
     * Constrain a query so it only matches when the viewer follows the owner of
     * the outer row. $ownerColumn is the qualified owner column on the outer
     * query (e.g. "media.user_id"); the correlation is what keeps this a single
     * SQL round-trip instead of an N+1.
     *
     * @param  QueryBuilder  $query  the inner builder passed by whereExists
     */
    public static function constrainViewerFollowsOwner(
        QueryBuilder $query,
        string $ownerColumn,
        int $viewerId,
        ?string $characterColumn = null,
    ): void {
        $query->from('follow_requests')
            ->whereColumn('follow_requests.recipient_id', $ownerColumn)
            ->where('follow_requests.requester_id', $viewerId)
            ->where('follow_requests.status', FollowRequest::STATUS_ACCEPTED);

        if ($characterColumn === null) {
            $query->whereNull('follow_requests.recipient_character_id');

            return;
        }

        $query->where(function (QueryBuilder $identity) use ($ownerColumn, $characterColumn): void {
            // The human identity accepts only an account-scoped edge.
            $identity->where(function (QueryBuilder $account) use ($characterColumn): void {
                $account->whereNull($characterColumn)
                    ->whereNull('follow_requests.recipient_character_id');
            })->orWhereExists(
                function (QueryBuilder $characters) use ($ownerColumn, $characterColumn): void {
                    // A persona identity must still exist and belong to the
                    // outer owner. This keeps the SQL form identical to
                    // followsIdentity() across deletes and ownership changes.
                    $characters->selectRaw('1')
                        ->from('characters as followed_characters')
                        ->whereColumn('followed_characters.id', $characterColumn)
                        ->whereColumn('followed_characters.user_id', $ownerColumn)
                        ->whereNull('followed_characters.deleted_at')
                        ->where(function (QueryBuilder $edge): void {
                            $edge->whereColumn(
                                'follow_requests.recipient_character_id',
                                'followed_characters.id',
                            )->orWhere(function (QueryBuilder $linkedAccount): void {
                                $linkedAccount
                                    ->whereNull('follow_requests.recipient_character_id')
                                    ->where('followed_characters.is_linked', true);
                            });
                        });
                });
        });
    }

    /**
     * Constrain a query so it only matches when the owner of the outer row
     * follows the viewer (the second leg of a mutual follow).
     *
     * @param  QueryBuilder  $query  the inner builder passed by whereExists
     */
    public static function constrainOwnerFollowsViewer(QueryBuilder $query, string $ownerColumn, int $viewerId): void
    {
        $query->from('follow_requests')
            ->whereColumn('follow_requests.requester_id', $ownerColumn)
            ->where('follow_requests.recipient_id', $viewerId)
            ->whereNull('follow_requests.recipient_character_id')
            ->where('follow_requests.status', FollowRequest::STATUS_ACCEPTED);
    }
}
