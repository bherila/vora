<?php

namespace App\Services\Privacy;

use App\Models\PrivacyAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes {@see PrivacyAuditLog} rows for privacy-policy changes. Centralising it
 * keeps every content type (Media, Story, future Posts) emitting the same
 * auditable shape from one place.
 *
 * @phpstan-type Snapshot array{audience: string, discoverable: bool, member_ids: list<int>}
 */
class PrivacyAuditor
{
    /**
     * Record a privacy change. For an update with no effective change nothing is
     * written; a creation is always recorded so the item's initial policy is
     * attributable.
     *
     * @param  Snapshot  $before
     * @param  Snapshot  $after
     */
    /**
     * Record the initial privacy policy of a newly created item.
     *
     * @param  Snapshot  $after
     */
    public function recordCreation(Model $content, ?User $actor, array $after, ?Request $request = null): void
    {
        $this->record(
            $content,
            $actor,
            ['audience' => '', 'discoverable' => false, 'member_ids' => []],
            $after,
            $request,
            PrivacyAuditLog::ACTION_CREATED,
        );
    }

    public function record(
        Model $content,
        ?User $actor,
        array $before,
        array $after,
        ?Request $request = null,
        string $action = PrivacyAuditLog::ACTION_UPDATED,
    ): void {
        $added = array_values(array_diff($after['member_ids'], $before['member_ids']));
        $removed = array_values(array_diff($before['member_ids'], $after['member_ids']));

        $audienceChanged = $before['audience'] !== $after['audience'];
        $discoverableChanged = $before['discoverable'] !== $after['discoverable'];
        $membersChanged = $added !== [] || $removed !== [];

        if ($action !== PrivacyAuditLog::ACTION_CREATED
            && ! $audienceChanged
            && ! $discoverableChanged
            && ! $membersChanged) {
            return;
        }

        PrivacyAuditLog::query()->create([
            'privacyable_type' => $content->getMorphClass(),
            'privacyable_id' => $content->getKey(),
            'user_id' => $actor?->id,
            'action' => $action,
            'old_audience' => $action === PrivacyAuditLog::ACTION_CREATED ? null : $before['audience'],
            'new_audience' => $after['audience'],
            'old_discoverable' => $action === PrivacyAuditLog::ACTION_CREATED ? null : $before['discoverable'],
            'new_discoverable' => $after['discoverable'],
            'added_user_ids' => $added === [] ? null : $added,
            'removed_user_ids' => $removed === [] ? null : $removed,
            'ip_address' => $request?->ip(),
            'user_agent' => $request !== null ? substr((string) $request->userAgent(), 0, 1024) : null,
        ]);
    }
}
