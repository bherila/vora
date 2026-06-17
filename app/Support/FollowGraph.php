<?php

namespace App\Support;

use App\Models\FollowRequest;
use App\Traits\HasPrivacyPolicy;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The single source of truth for reading the follow graph.
 *
 * A follow edge is an accepted {@see FollowRequest}: requester_id follows
 * recipient_id. "X follows Y" therefore means a row
 * (requester_id = X, recipient_id = Y, status = accepted). Mutuals are a follow
 * in both directions.
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
            ->where('status', FollowRequest::STATUS_ACCEPTED)
            ->exists();
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
    public static function constrainViewerFollowsOwner(QueryBuilder $query, string $ownerColumn, int $viewerId): void
    {
        $query->from('follow_requests')
            ->whereColumn('follow_requests.recipient_id', $ownerColumn)
            ->where('follow_requests.requester_id', $viewerId)
            ->where('follow_requests.status', FollowRequest::STATUS_ACCEPTED);
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
            ->where('follow_requests.status', FollowRequest::STATUS_ACCEPTED);
    }
}
