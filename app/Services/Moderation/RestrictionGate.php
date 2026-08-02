<?php

namespace App\Services\Moderation;

use App\Enums\RestrictionCapability;
use App\Models\User;
use App\Models\UserRestriction;
use Illuminate\Support\Collection;

/**
 * Request-scoped source of truth for capability restrictions. Active rows are
 * loaded once per user for the request; expiry is still evaluated by the query
 * on every new request, so no scheduler is involved.
 */
class RestrictionGate
{
    /** @var array<int, Collection<int, UserRestriction>> */
    private array $activeByUser = [];

    public function denies(User $user, RestrictionCapability $capability): bool
    {
        return $this->restriction($user, $capability) instanceof UserRestriction;
    }

    public function restriction(User $user, RestrictionCapability $capability): ?UserRestriction
    {
        return $this->activeFor($user)
            ->first(fn (UserRestriction $restriction): bool => $restriction->capability === $capability);
    }

    /** @return Collection<int, UserRestriction> */
    public function activeFor(User $user): Collection
    {
        return $this->activeByUser[$user->id] ??= $user->restrictions()
            ->active()
            ->latest('id')
            ->get();
    }

    /** @return list<array<string, mixed>> */
    public function subjectPayload(User $user): array
    {
        return $this->activeFor($user)
            ->unique(fn (UserRestriction $restriction): string => $restriction->capability->value)
            ->map(fn (UserRestriction $restriction): array => [
                'capability' => $restriction->capability->value,
                'label' => $restriction->capability->label(),
                'reason' => $restriction->reason,
                'expires_at' => $restriction->expires_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
