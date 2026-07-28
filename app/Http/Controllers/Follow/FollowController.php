<?php

namespace App\Http\Controllers\Follow;

use App\Enums\Audience;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Story\AuthorshipInviteController;
use App\Models\Character;
use App\Models\Favorite;
use App\Models\FollowRequest;
use App\Models\FollowRequestAuditLog;
use App\Models\InterestRating;
use App\Models\User;
use App\Notifications\FollowRequestAccepted;
use App\Notifications\FollowRequestReceived;
use App\Services\Media\MediaResponseService;
use App\Services\Privacy\ProfileGate;
use App\Services\Profile\ProfileContentQueries;
use App\Support\CharacterPresenter;
use App\Support\FollowGraph;
use App\Support\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class FollowController extends Controller
{
    public function __construct(
        private readonly ProfileGate $gate,
        private readonly MediaResponseService $mediaResponder,
        private readonly ProfileContentQueries $contentQueries,
    ) {}

    public function directory(Request $request): View
    {
        return view('user.follow-directory', ['initialData' => [
            'followDirectory' => $this->usersPayload($request),
        ]]);
    }

    public function profilePage(Request $request, User $user): View
    {
        $current = $request->user();
        if (! $current instanceof User || $current->is($user) || ! $this->isDiscoverable($user)) {
            abort(404);
        }

        return view('user.follow-profile', [
            'initialData' => ['followProfile' => $this->profilePayload($current, $user)],
        ]);
    }

    /**
     * The signed-in user's own profile, rendered through the same container view
     * as other people's profiles but in owner mode (is_self = true).
     */
    public function me(Request $request): View
    {
        $current = $request->user();
        $characters = $current->characters()->with(['profilePicture', 'audienceMembers'])->latest()->get();

        return view('user.follow-profile', [
            'initialData' => [
                'followProfile' => $this->profilePayload($current, $current),
                'profileEditable' => $this->editablePayload($current),
                'profileMedia' => $this->profileMediaPayload($current),
                // Full editable character records for the owner's character editor.
                'profileCharacters' => CharacterPresenter::list($characters, $this->mediaResponder),
                // Per-identity content totals for the identity rail. Personas are
                // opt-in: with none, the rail is absent and the totals are skipped
                // entirely rather than hydrated as an empty shell.
                'profileIdentityCounts' => $characters->isEmpty()
                    ? null
                    : $this->contentQueries->identityTotals($current, $characters),
            ],
        ]);
    }

    /**
     * Upload context for the owner's own profile: the character options (with
     * inherited privacy) the upload dialog offers, plus the interests pre-filled
     * from the user's last upload. Media is uploaded on the profile, so this is
     * hydrated into /me rather than a separate library page.
     *
     * @return array{characters: list<array<string, mixed>>, last_interest_ids: list<int>}
     */
    private function profileMediaPayload(User $user): array
    {
        $characters = $user->characters()
            ->with('audienceMembers')
            ->orderBy('display_name')
            ->get()
            ->map(fn (Character $character): array => [
                'id' => $character->id,
                'display_name' => $character->display_name,
                'audience' => $character->audience->value,
                'audience_user_ids' => $character->audience === Audience::SpecificPeople
                    ? $character->audienceMembers()->pluck('user_id')->map('intval')->sort()->values()->all()
                    : [],
            ])
            ->values()
            ->all();

        return [
            'characters' => $characters,
            'last_interest_ids' => array_values(array_map('intval', $user->last_media_interest_ids ?? [])),
        ];
    }

    /**
     * The owner's editable identity, hydrated into /me so the inline editor can
     * patch /api/account. Name and email are included so the editor can resend
     * them unchanged (the endpoint requires them); account/security fields live
     * in Settings, not here.
     *
     * @return array<string, mixed>
     */
    private function editablePayload(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'display_name' => $user->display_name,
            'bio' => $user->bio,
            'pronouns' => $user->pronouns,
            'gender' => $user->gender,
            'gender_other' => $user->gender_other,
            'user_type' => $user->user_type,
            'user_type_other' => $user->user_type_other,
            'preferred_user_types' => $user->preferred_user_types ?? [],
            'preferred_genders' => $user->preferred_genders ?? [],
            'profile_audience' => $user->profile_audience?->value ?? 'everyone',
            'audience_user_ids' => $user->profileAudienceMembers()->pluck('user_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'can_manage_interests' => (bool) (! $user->is_disabled && $user->hasVerifiedEmail() && $user->isApproved()),
        ];
    }

    public function inboxPage(Request $request): View
    {
        return view('user.follow-requests', ['initialData' => [
            'followRequests' => [
                'requests' => $this->inboxPayload($request),
                'invites' => app(AuthorshipInviteController::class)->inboxPayload($request->user()),
            ],
        ]]);
    }

    public function users(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->usersPayload($request)]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function usersPayload(Request $request): Collection
    {
        $current = $request->user();
        $query = User::query()->whereKeyNot($current?->id)->whereNotNull('approved_at')->active();

        if ($request->query('relationship') === 'mutuals') {
            if (! $current instanceof User) {
                return collect();
            }

            $query
                ->whereExists(fn ($sub) => FollowGraph::constrainViewerFollowsOwner($sub, 'users.id', $current->id))
                ->whereExists(fn ($sub) => FollowGraph::constrainOwnerFollowsViewer($sub, 'users.id', $current->id));
        }

        $users = $query->with(['profilePicture', 'interestRatings' => function ($q): void {
            $q->whereNull('character_id')->where('level', '>', 0);
        }])->get();

        $viewerInterestIds = $current instanceof User
            ? InterestRating::query()
                ->where('user_id', $current->id)
                ->whereNull('character_id')
                ->where('level', '>', 0)
                ->pluck('interest_id')
                ->map(fn ($id): int => (int) $id)
                ->all()
            : [];
        $viewerInterestCount = count($viewerInterestIds);

        // Restricted profiles still appear in the directory so they remain
        // findable for a follow request, but their details are withheld from
        // viewers their audience tier doesn't admit.
        $canView = $current instanceof User ? $this->gate->canViewMany($current, $users) : [];

        return $users->map(function (User $user) use ($canView, $current, $viewerInterestIds, $viewerInterestCount): array {
            $visible = $canView[$user->id] ?? false;
            $matchingInterestCount = $visible
                ? ($viewerInterestCount > 0
                    ? $user->interestRatings->pluck('interest_id')->intersect($viewerInterestIds)->count()
                    : 0)
                : null;
            $interestMatchScore = $matchingInterestCount !== null
                ? ($viewerInterestCount > 0 ? (int) round(($matchingInterestCount / $viewerInterestCount) * 100) : 0)
                : null;

            return [
                'id' => $user->id,
                'display_name' => $user->display_name ?: $user->name,
                'avatar_url' => UserPresenter::avatarUrl($user, $this->mediaResponder, $current),
                'restricted' => ! $visible,
                'user_type' => $visible ? $user->user_type : null,
                'gender' => $visible ? $user->gender : null,
                'matching_interests_count' => $matchingInterestCount,
                'interest_match_score' => $interestMatchScore,
            ];
        })->sortBy([
            ['interest_match_score', 'desc'],
            ['matching_interests_count', 'desc'],
            ['display_name', 'asc'],
        ])->values();
    }

    public function profile(Request $request, User $user): JsonResponse
    {
        $current = $request->user();
        if (! $current instanceof User || $current->is($user) || ! $this->isDiscoverable($user)) {
            return response()->json(['success' => false, 'message' => 'Profile unavailable.'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->profilePayload($current, $user)]);
    }

    /**
     * The viewer-specific profile payload shared by the hydrated page
     * ({@see self::profilePage()}) and the refresh endpoint ({@see self::profile()}).
     *
     * @return array<string, mixed>
     */
    private function profilePayload(User $current, User $user): array
    {
        $followRequest = FollowRequest::query()->where('requester_id', $current->id)->where('recipient_id', $user->id)->first();

        $isSelf = $current->id === $user->id;

        // Always present, even on a restricted profile, so the viewer can still
        // send / track a follow request.
        $base = [
            'id' => $user->id,
            'is_self' => $isSelf,
            'display_name' => $user->display_name ?: $user->name,
            'avatar_url' => UserPresenter::avatarUrl($user, $this->mediaResponder, $current),
            'follow_request' => $this->followRequestPayload($followRequest),
        ];

        if (! $this->gate->canView($current, $user)) {
            return $base + [
                'restricted' => true,
                'bio' => null,
                'pronouns' => null,
                'user_type' => null,
                'gender' => null,
                'mutual_interests' => [],
                'can_follow_back' => false,
                'characters' => [],
            ];
        }

        $currentInterestIds = InterestRating::query()->where('user_id', $current->id)->whereNull('character_id')->where('level', '>', 0)->pluck('interest_id');
        $mutualInterests = InterestRating::query()
            ->with('interest:id,name')
            ->where('user_id', $user->id)
            ->whereNull('character_id')
            ->where('level', '>', 0)
            ->whereIn('interest_id', $currentInterestIds)
            ->get()
            ->map(fn (InterestRating $rating): array => ['id' => $rating->interest_id, 'name' => $rating->interest?->name])
            ->all();

        $incoming = FollowRequest::query()->where('requester_id', $user->id)->where('recipient_id', $current->id)->where('status', 'accepted')->exists();

        $viewerFavorited = ! $isSelf && Favorite::query()
            ->where('user_id', $current->id)
            ->where('favoritable_type', $user->getMorphClass())
            ->where('favoritable_id', $user->id)
            ->exists();

        return $base + [
            'restricted' => false,
            'bio' => $user->bio,
            'pronouns' => $user->pronouns,
            'user_type' => $user->user_type,
            'gender' => $user->gender,
            'mutual_interests' => $mutualInterests,
            'can_follow_back' => $incoming && ($followRequest === null || $followRequest->status !== 'accepted'),
            'characters' => $this->charactersStrip($user, $current),
            'viewer_favorited' => $viewerFavorited,
        ];
    }

    /**
     * The profile's characters, for the identity strip across the top of the
     * profile-as-container view. A character a viewer cannot see by audience is
     * still listed here as an identity to switch to; its *content* tabs run the
     * same per-item privacy filter, so nothing is exposed by listing the name.
     *
     * @return list<array<string, mixed>>
     */
    private function charactersStrip(User $user, ?User $viewer): array
    {
        return $user->characters()
            ->with('profilePicture')
            ->orderBy('display_name')
            ->get()
            ->map(fn (Character $character): array => [
                'id' => $character->id,
                'display_name' => $character->display_name,
                'avatar_url' => UserPresenter::pictureUrl($character->profilePicture, $this->mediaResponder, $viewer),
            ])
            ->all();
    }

    public function requestFollow(Request $request, User $user): JsonResponse
    {
        $current = $request->user();
        if (! $current instanceof User || $current->is($user) || ! $this->isDiscoverable($user)) {
            return response()->json(['success' => false, 'message' => 'You cannot follow this user.'], 422);
        }

        $followRequest = FollowRequest::query()->firstOrNew(['requester_id' => $current->id, 'recipient_id' => $user->id]);
        if ($followRequest->exists && $followRequest->status === 'declined' && ! $this->declinedRequestCanBeRetried($followRequest)) {
            return response()->json(['success' => false, 'message' => 'You can request again 24 hours after the request was declined.'], 429);
        }
        if ($followRequest->exists && in_array($followRequest->status, ['pending', 'accepted'], true)) {
            return response()->json(['success' => false, 'message' => 'A follow request already exists.'], 422);
        }

        $followRequest->status = 'pending';
        $followRequest->responded_at = null;
        $followRequest->save();
        $this->audit($followRequest, $current, 'requested');

        if ($user->notify_follow_request) {
            $user->notify(new FollowRequestReceived($followRequest->load('requester')));
        }

        return response()->json(['success' => true, 'data' => ['status' => 'pending']]);
    }

    public function inbox(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->inboxPayload($request)]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function inboxPayload(Request $request): Collection
    {
        $current = $request->user();
        // Hide requests from accounts that have since deactivated, been disabled,
        // or deleted — whereHas('requester') drops soft-deleted requesters via the
        // User scope; active() covers deactivated + disabled.
        $requests = FollowRequest::query()->with(['requester:id,name,display_name,user_type,gender,profile_audience,profile_picture_media_id', 'requester.profilePicture'])
            ->whereHas('requester', fn ($q) => $q->active())
            ->where('recipient_id', $current?->id)->where('status', 'pending')->latest()->get();

        // The inbox is another surface onto a requester's profile, so it honours
        // the same audience gate as the directory: details are withheld unless
        // the recipient may view that requester's profile.
        $requesters = $requests->pluck('requester')->filter()->values();
        $canView = $current instanceof User ? $this->gate->canViewMany($current, $requesters) : [];

        return $requests->map(function (FollowRequest $followRequest) use ($canView): array {
            $requester = $followRequest->requester;
            $visible = $requester !== null && ($canView[$requester->id] ?? false);

            return [
                'id' => $followRequest->id,
                'requester' => [
                    'id' => $requester?->id,
                    'display_name' => $requester?->display_name ?: $requester?->name,
                    'avatar_url' => UserPresenter::avatarUrl($requester, $this->mediaResponder),
                    'restricted' => ! $visible,
                    'user_type' => $visible ? $requester?->user_type : null,
                    'gender' => $visible ? $requester?->gender : null,
                ],
                'created_at' => $followRequest->created_at?->toIso8601String(),
            ];
        });
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['count' => FollowRequest::query()
            ->whereHas('requester', fn ($q) => $q->active())
            ->where('recipient_id', $request->user()?->id)->where('status', 'pending')->count()]]);
    }

    public function accept(Request $request, FollowRequest $followRequest): JsonResponse
    {
        return $this->decide($request, $followRequest, 'accepted');
    }

    public function decline(Request $request, FollowRequest $followRequest): JsonResponse
    {
        return $this->decide($request, $followRequest, 'declined');
    }

    private function decide(Request $request, FollowRequest $followRequest, string $status): JsonResponse
    {
        $current = $request->user();
        if (! $current instanceof User || $followRequest->recipient_id !== $current->id || $followRequest->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Follow request unavailable.'], 404);
        }

        // A requester who deactivated, was disabled, or deleted after sending must
        // stay hidden: requester is null once soft-deleted (User scope), and
        // isActive() covers the deactivated + disabled states.
        $requester = $followRequest->requester;
        if ($requester === null || ! $requester->isActive()) {
            return response()->json(['success' => false, 'message' => 'Follow request unavailable.'], 404);
        }

        $followRequest->status = $status;
        $followRequest->responded_at = Carbon::now();
        $followRequest->save();
        $this->audit($followRequest, $current, $status);

        if ($status === 'accepted') {
            if ($followRequest->requester?->notify_follow_accepted) {
                $followRequest->requester->notify(new FollowRequestAccepted($followRequest->load('recipient')));
            }
        }

        return response()->json(['success' => true, 'data' => ['status' => $status]]);
    }

    private function isDiscoverable(User $user): bool
    {
        return $user->approved_at !== null && $user->isActive();
    }

    private function declinedRequestCanBeRetried(FollowRequest $followRequest): bool
    {
        return $followRequest->status === 'declined'
            && ($followRequest->responded_at === null || $followRequest->responded_at->lte(now()->subDay()));
    }

    private function followRequestPayload(?FollowRequest $followRequest): ?array
    {
        if ($followRequest === null) {
            return null;
        }

        return [
            'status' => $followRequest->status,
            'updated_at' => $followRequest->updated_at?->toIso8601String(),
            'can_retry' => $this->declinedRequestCanBeRetried($followRequest),
        ];
    }

    private function audit(FollowRequest $followRequest, User $actor, string $action): void
    {
        FollowRequestAuditLog::query()->create([
            'follow_request_id' => $followRequest->id,
            'actor_id' => $actor->id,
            'requester_id' => $followRequest->requester_id,
            'recipient_id' => $followRequest->recipient_id,
            'action' => $action,
        ]);
    }
}
