<?php

namespace App\Traits;

use App\Enums\Visibility;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reusable visibility behaviour for user-owned content. The using model must
 * have a `visibility` column cast to {@see Visibility} and a `user_id` owner
 * column. Designed to be shared by Media today and future content (e.g.
 * Stories) without duplication.
 */
trait HasVisibility
{
    /**
     * Restrict a query to rows the viewer may discover in listings. Owners see
     * their own content, admins see everything, and everyone else sees only
     * content marked visible to any user. Unlisted content is intentionally
     * excluded — it is reachable only via a direct link (see isVisibleTo).
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        if ($viewer !== null && $viewer->isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($viewer): void {
            $q->where('visibility', Visibility::Users->value);

            if ($viewer !== null) {
                $q->orWhere('user_id', $viewer->id);
            }
        });
    }

    /**
     * Whether the viewer may see this specific record, including via a direct
     * link. Possession of the link (the record's ULID) authorises access to
     * unlisted content, which is why direct lookups allow it while listings
     * (scopeVisibleTo) do not.
     */
    public function isVisibleTo(?User $viewer): bool
    {
        if ($viewer !== null && ($viewer->isAdmin() || $viewer->id === $this->user_id)) {
            return true;
        }

        if ($viewer === null) {
            return false;
        }

        return $this->visibility === Visibility::Users
            || $this->visibility === Visibility::Unlisted;
    }
}
