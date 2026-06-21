<?php

namespace App\Traits;

use App\Enums\Audience;
use App\Models\AudienceMember;
use App\Models\User;
use App\Services\Privacy\PrivacyAuditor;
use App\Support\FollowGraph;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Reusable, DRY privacy behaviour for user-owned content. The using model must
 * have an `audience` column cast to {@see Audience}, a `discoverable` boolean,
 * and a `user_id` owner column. Shared today by Media and Story and intended for
 * Posts and any future content without duplicating the authorization rule.
 *
 * Two orthogonal axes:
 *   - audience      — WHO may access (everyone / followers / mutuals / specific).
 *                     Always enforced; the owner and admins are the only bypass.
 *   - discoverable  — whether the item is listed on discovery surfaces, or only
 *                     reachable via its direct link. A share link NEVER escalates
 *                     access beyond the audience tier (see isViewableBy): direct
 *                     lookups run the same audience check as listings.
 *
 * All follow-graph reads go through {@see FollowGraph} so the rule lives in one
 * auditable place.
 */
trait HasPrivacyPolicy
{
    /**
     * Prune the allowlist when the host content is deleted — the polymorphic
     * pivot has no database FK on its target, so a model hook is what keeps a
     * deleted item from leaving orphaned grants behind. The append-only audit
     * trail is intentionally retained. Applies to every model using the trait.
     */
    public static function bootHasPrivacyPolicy(): void
    {
        static::deleting(function (self $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            $model->audienceMembers()->delete();
        });
    }

    /**
     * The explicit per-item allowlist used by the SpecificPeople audience.
     *
     * @return MorphMany<AudienceMember, $this>
     */
    public function audienceMembers(): MorphMany
    {
        return $this->morphMany(AudienceMember::class, 'privacyable');
    }

    /**
     * Restrict a query to rows the viewer is authorized to view, by audience
     * tier — owner and admins always pass. This is the authorization gate and is
     * independent of discoverability: it governs both listings and direct
     * lookups, so a share link cannot widen access.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeViewableBy(Builder $query, ?User $viewer): Builder
    {
        if ($viewer !== null && $viewer->isAdmin()) {
            return $query;
        }

        $table = $this->getTable();
        $morph = $this->getMorphClass();
        if (method_exists($this, 'getDeletedAtColumn')) {
            $query->whereNull($table.'.'.$this->getDeletedAtColumn());
        }

        return $query->where(function (Builder $q) use ($viewer, $table, $morph): void {
            // No authenticated viewer sees nothing — content is never served to
            // guests (mirrors isViewableBy(null) === false). This keeps the scope
            // safe to reuse on any future guest-facing listing.
            if ($viewer === null) {
                $q->whereRaw('1 = 0');

                return;
            }

            // Everyone is viewable to any signed-in viewer.
            $q->where($table.'.audience', Audience::Everyone->value);

            $viewerId = $viewer->id;

            // The owner always sees their own content, on any tier.
            $q->orWhere($table.'.user_id', $viewerId);

            // Followers tier: viewer follows the owner.
            $q->orWhere(function (Builder $f) use ($table, $viewerId): void {
                $f->where($table.'.audience', Audience::Followers->value)
                    ->whereExists(function (QueryBuilder $sub) use ($table, $viewerId): void {
                        FollowGraph::constrainViewerFollowsOwner($sub, $table.'.user_id', $viewerId);
                    });
            });

            // Mutuals tier: viewer follows the owner AND the owner follows back.
            $q->orWhere(function (Builder $m) use ($table, $viewerId): void {
                $m->where($table.'.audience', Audience::Mutuals->value)
                    ->whereExists(function (QueryBuilder $sub) use ($table, $viewerId): void {
                        FollowGraph::constrainViewerFollowsOwner($sub, $table.'.user_id', $viewerId);
                    })
                    ->whereExists(function (QueryBuilder $sub) use ($table, $viewerId): void {
                        FollowGraph::constrainOwnerFollowsViewer($sub, $table.'.user_id', $viewerId);
                    });
            });

            // Specific people: viewer is on the item's allowlist.
            $q->orWhere(function (Builder $s) use ($table, $morph, $viewerId): void {
                $s->where($table.'.audience', Audience::SpecificPeople->value)
                    ->whereExists(function (QueryBuilder $sub) use ($table, $morph, $viewerId): void {
                        $sub->from('audience_members')
                            ->whereColumn('audience_members.privacyable_id', $table.'.id')
                            ->where('audience_members.privacyable_type', $morph)
                            ->where('audience_members.user_id', $viewerId);
                    });
            });
        });
    }

    /**
     * Restrict a query to rows that may appear on a public discovery surface
     * (e.g. Explore): strictly the Everyone audience AND opted into discovery.
     * There is no owner or admin exception — a non-discoverable or
     * audience-restricted item must never be listed in public discovery.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDiscoverable(Builder $query): Builder
    {
        $table = $this->getTable();

        if (method_exists($this, 'getDeletedAtColumn')) {
            $query->whereNull($table.'.'.$this->getDeletedAtColumn());
        }

        return $query->where($table.'.audience', Audience::Everyone->value)
            ->where($table.'.discoverable', true);
    }

    /**
     * Whether the viewer may see this specific record. The audience tier is
     * always enforced — there is intentionally NO link/ULID bypass, so direct
     * access is gated exactly like a listing. Owner and admins always pass.
     */
    public function isViewableBy(?User $viewer): bool
    {
        if (method_exists($this, 'trashed') && $this->trashed()) {
            return $viewer !== null && $viewer->isAdmin();
        }

        if ($viewer !== null && ($viewer->isAdmin() || $viewer->id === $this->user_id)) {
            return true;
        }

        if ($viewer === null) {
            return false;
        }

        return match ($this->audience) {
            Audience::Everyone => true,
            Audience::Followers => FollowGraph::follows($viewer->id, $this->user_id),
            Audience::Mutuals => FollowGraph::mutual($viewer->id, $this->user_id),
            Audience::SpecificPeople => $this->audienceMembers()
                ->where('user_id', $viewer->id)
                ->exists(),
        };
    }

    /**
     * Replace this item's allowlist with the given user ids, returning the
     * granted/revoked diff so the caller can write an audit record. Only
     * meaningful for the SpecificPeople audience, but kept idempotent for any.
     *
     * @param  list<int>  $userIds
     * @return array{added: list<int>, removed: list<int>}
     */
    public function syncAudienceMembers(array $userIds): array
    {
        $target = array_values(array_unique(array_map('intval', $userIds)));
        $existing = $this->audienceMembers()->pluck('user_id')->map('intval')->all();

        $added = array_values(array_diff($target, $existing));
        $removed = array_values(array_diff($existing, $target));

        if ($removed !== []) {
            $this->audienceMembers()->whereIn('user_id', $removed)->delete();
        }

        foreach ($added as $userId) {
            $this->audienceMembers()->create(['user_id' => $userId]);
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * A snapshot of the current privacy policy, used as the before/after state
     * for {@see PrivacyAuditor}.
     *
     * @return array{audience: string, discoverable: bool, member_ids: list<int>}
     */
    public function privacySnapshot(): array
    {
        return [
            'audience' => $this->audience->value,
            'discoverable' => (bool) $this->discoverable,
            'member_ids' => $this->audience === Audience::SpecificPeople
                ? $this->audienceMembers()->pluck('user_id')->map('intval')->sort()->values()->all()
                : [],
        ];
    }
}
