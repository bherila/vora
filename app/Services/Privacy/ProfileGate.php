<?php

namespace App\Services\Privacy;

use App\Enums\Audience;
use App\Models\AudienceMember;
use App\Models\FollowRequest;
use App\Models\User;
use App\Support\BlockGraph;
use App\Support\FollowGraph;
use Illuminate\Support\Collection;

/**
 * Decides who may view a user's profile, by the profile's audience tier. Owner
 * and admins always pass. Reuses {@see FollowGraph} and the shared
 * audience_members allowlist so the rule matches content privacy.
 */
class ProfileGate
{
    /**
     * Single-profile check.
     */
    public function canView(?User $viewer, User $target): bool
    {
        if ($viewer === null) {
            return false;
        }

        if ($viewer->id === $target->id || $viewer->isAdmin()) {
            return true;
        }

        if (! BlockGraph::canViewIdentity($viewer, $target->id)) {
            return false;
        }

        return match ($target->profile_audience) {
            Audience::Everyone => true,
            Audience::Followers => FollowGraph::follows($viewer->id, $target->id),
            Audience::Mutuals => FollowGraph::mutual($viewer->id, $target->id),
            Audience::SpecificPeople => $target->profileAudienceMembers()
                ->where('user_id', $viewer->id)
                ->exists(),
        };
    }

    /**
     * Batch check for a listing (e.g. the directory): preloads the viewer's
     * follow graph and profile grants once to avoid an N+1.
     *
     * @param  Collection<int, User>  $targets
     * @return array<int, bool> keyed by target user id
     */
    public function canViewMany(User $viewer, Collection $targets): array
    {
        if ($viewer->isAdmin()) {
            return $targets->mapWithKeys(fn (User $target): array => [$target->id => true])->all();
        }

        $targetIds = $targets->pluck('id');
        $following = FollowRequest::query()
            ->where('requester_id', $viewer->id)
            ->whereIn('recipient_id', $targetIds)
            ->where('status', FollowRequest::STATUS_ACCEPTED)
            ->whereNull('recipient_character_id')
            ->pluck('recipient_id')->flip();
        $followers = FollowRequest::query()
            ->where('recipient_id', $viewer->id)
            ->whereIn('requester_id', $targetIds)
            ->where('status', FollowRequest::STATUS_ACCEPTED)
            ->whereNull('recipient_character_id')
            ->pluck('requester_id')->flip();
        $granted = AudienceMember::query()
            ->where('privacyable_type', (new User)->getMorphClass())
            ->where('user_id', $viewer->id)
            ->whereIn('privacyable_id', $targetIds)
            ->pluck('privacyable_id')->flip();
        $blocked = BlockGraph::hiddenAccountIds($viewer, $targets);

        return $targets->mapWithKeys(function (User $target) use ($viewer, $following, $followers, $granted, $blocked): array {
            if ($target->id === $viewer->id) {
                return [$target->id => true];
            }

            if ($blocked->has($target->id)) {
                return [$target->id => false];
            }

            $ok = match ($target->profile_audience) {
                Audience::Everyone => true,
                Audience::Followers => $following->has($target->id),
                Audience::Mutuals => $following->has($target->id) && $followers->has($target->id),
                Audience::SpecificPeople => $granted->has($target->id),
            };

            return [$target->id => $ok];
        })->all();
    }
}
