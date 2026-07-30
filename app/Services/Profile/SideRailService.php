<?php

namespace App\Services\Profile;

use App\Enums\ModerationStatus;
use App\Models\FollowRequest;
use App\Models\InterestRating;
use App\Models\Media;
use App\Models\Post;
use App\Models\RecentProfileVisit;
use App\Models\Story;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use App\Services\Privacy\ProfileGate;
use App\Support\BlockGraph;
use App\Support\MuteGraph;
use App\Support\UserPresenter;
use Illuminate\Support\Collection;

class SideRailService
{
    public function __construct(
        private readonly RecentProfileTrail $trail,
        private readonly ProfileGate $profileGate,
        private readonly MediaResponseService $mediaResponder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(User $viewer): array
    {
        return [
            'pending_actions' => [
                [
                    'label' => 'Follow requests',
                    'count' => $viewer->receivedFollowRequests()
                        ->whereNull('recipient_character_id')
                        ->where('status', FollowRequest::STATUS_PENDING)
                        ->whereHas('requester', fn ($query) => $query->active())
                        ->whereNotExists(fn ($blocks) => BlockGraph::constrainDenied(
                            $blocks,
                            'follow_requests.requester_id',
                            $viewer->id,
                        ))
                        ->whereNotExists(fn ($blocks) => BlockGraph::constrainHidden(
                            $blocks,
                            'follow_requests.requester_id',
                            $viewer->id,
                        ))
                        ->count(),
                    'href' => route('users.follow-requests', [], false),
                ],
                [
                    'label' => 'Co-author invites',
                    'count' => $viewer->storyAuthorships()->pendingForActiveOwner()->count(),
                    'href' => route('users.follow-requests', [], false),
                ],
                [
                    'label' => 'Items under review',
                    'count' => $this->underReviewCount($viewer),
                    'href' => route('me', [], false),
                ],
            ],
            'suggested_people' => $this->suggestedPeople($viewer),
            'recently_visited' => $this->trail->cards($viewer),
        ];
    }

    private function underReviewCount(User $viewer): int
    {
        return Media::query()->where('user_id', $viewer->id)->where('moderation_status', ModerationStatus::Pending)->count()
            + Story::query()->where('user_id', $viewer->id)->where('moderation_status', ModerationStatus::Pending)->count()
            + Post::query()->where('user_id', $viewer->id)->where('moderation_status', ModerationStatus::Pending)->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function suggestedPeople(User $viewer): array
    {
        $alreadyKnown = FollowRequest::query()
            ->where('requester_id', $viewer->id)
            ->whereNull('recipient_character_id')
            ->pluck('recipient_id');
        $viewerInterestIds = InterestRating::query()
            ->where('user_id', $viewer->id)
            ->whereNull('character_id')
            ->where('level', '>', 0)
            ->pluck('interest_id');

        $users = User::query()
            ->active()
            ->whereNotNull('approved_at')
            ->whereKeyNot($viewer->id)
            ->whereNotIn('id', $alreadyKnown)
            // Do not place the human owner beside a recently visited Separate
            // persona. The cards do not identify that relationship, and the
            // suggestions section must not accidentally reconstruct it.
            ->whereNotIn('id', $this->separatePersonaOwnerIdsInTrail($viewer))
            ->with(['profilePicture', 'interestRatings' => fn ($query) => $query
                ->whereNull('character_id')
                ->where('level', '>', 0)]);
        BlockGraph::visibleTo($users, $viewer, 'users.id');
        MuteGraph::excludeMutedIdentities($users, $viewer->id, 'users.id');
        $users = $users
            ->get();
        $canView = $this->profileGate->canViewMany($viewer, $users);
        $interestCount = $viewerInterestIds->count();

        return $users
            ->filter(fn (User $user): bool => $canView[$user->id] ?? false)
            ->map(function (User $user) use ($viewer, $viewerInterestIds, $interestCount): array {
                $matching = $user->interestRatings->pluck('interest_id')->intersect($viewerInterestIds)->count();

                return [
                    'id' => $user->id,
                    'display_name' => $user->display_name ?: $user->name,
                    'avatar_url' => UserPresenter::avatarUrl($user, $this->mediaResponder, $viewer),
                    'href' => route('users.profile', ['user' => $user->id], false),
                    'interest_match_score' => $interestCount > 0
                        ? (int) round(($matching / $interestCount) * 100)
                        : 0,
                    'matching_interests_count' => $matching,
                ];
            })
            ->sortBy([
                ['interest_match_score', 'desc'],
                ['matching_interests_count', 'desc'],
                ['display_name', 'asc'],
            ])
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, int>
     */
    private function separatePersonaOwnerIdsInTrail(User $viewer): Collection
    {
        return RecentProfileVisit::query()
            ->join('characters', 'characters.id', '=', 'recent_profile_visits.target_id')
            ->where('recent_profile_visits.viewer_user_id', $viewer->id)
            ->where('recent_profile_visits.target_type', RecentProfileVisit::TARGET_CHARACTER)
            ->where('recent_profile_visits.visited_at', '>=', now()->subDays(RecentProfileTrail::RETENTION_DAYS))
            ->where('characters.is_linked', false)
            ->pluck('characters.user_id');
    }
}
