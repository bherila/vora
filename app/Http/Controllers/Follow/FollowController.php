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
use App\Services\Privacy\ViewAsContext;
use App\Services\Profile\PersonaProfilePayload;
use App\Services\Profile\ProfileContentQueries;
use App\Support\ActiveIdentity;
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
        private readonly ActiveIdentity $activeIdentity,
        private readonly PersonaProfilePayload $personaProfile,
        private readonly ViewAsContext $viewAs,
    ) {}

    public function directory(Request $request): View
    {
        return view('user.follow-directory', ['initialData' => [
            'followDirectory' => $this->usersPayload($request),
            'followDirectoryPersonas' => $this->directoryPersonasPayload($request),
        ]]);
    }

    /**
     * Discoverable personas for the People directory: strictly Everyone-audience,
     * discovery-opted personas of active, approved owners — `discoverable = false`
     * means direct-link-only, so those never appear here. Unlike restricted user
     * profiles (listed name-only so a follow request stays possible), restricted
     * personas are omitted entirely: there is no persona follow request to send,
     * so listing them would expose a name for nothing. No interest matching:
     * affinity scoring finds real people, not fictional characters — and matching
     * an inheriting persona would carry the owner's interest fingerprint.
     *
     * @return list<array<string, mixed>>
     */
    private function directoryPersonasPayload(Request $request): array
    {
        return Character::query()
            ->discoverable()
            ->whereHas('user', fn ($q) => $q->active()->whereNotNull('approved_at'))
            ->with('profilePicture')
            ->orderBy('display_name')
            ->get()
            ->map(fn (Character $character): array => CharacterPresenter::publicCard($character, $this->mediaResponder, $request->user()))
            ->values()
            ->all();
    }

    public function profilePage(Request $request, User $user): View
    {
        $current = $request->user();
        if ($request->query('view_as') !== null) {
            // View-as belongs exclusively to the owner's /me surface.
            $this->viewAs->viewerFor($request, $user);
            abort(404, 'Not found.');
        }
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
        $activeCharacterId = $this->activeIdentity->id($request, $current);
        $activeCharacter = $activeCharacterId !== null
            ? $current->characters()->with(['user', 'profilePicture'])->find($activeCharacterId)
            : null;
        $viewer = $this->viewAs->viewerFor($request, $current, $activeCharacter);

        if ($this->viewAs->mode() !== null && $activeCharacter instanceof Character) {
            if (! $activeCharacter->isViewableBy($viewer)) {
                abort(404, 'Not found.');
            }

            return view('user.follow-profile', [
                'initialData' => [
                    'personaProfile' => $this->personaProfile->build(
                        $activeCharacter,
                        $viewer,
                        allowMutations: false,
                    ),
                    'profileViewAs' => $this->viewAs->payload(),
                ],
            ]);
        }

        if ($this->viewAs->mode() !== null) {
            return view('user.follow-profile', [
                'initialData' => [
                    'followProfile' => $this->profilePayload($viewer, $current),
                    'profileViewAs' => $this->viewAs->payload(),
                ],
            ]);
        }

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
                'profileViewAs' => null,
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
        if ($request->query('view_as') !== null) {
            $viewer = $this->viewAs->viewerFor($request, $user);

            return response()->json(['success' => true, 'data' => $this->profilePayload($viewer, $user)]);
        }
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
        // Human profile state must never be inferred from a persona-scoped edge.
        $followRequest = FollowRequest::query()
            ->where('requester_id', $current->id)
            ->where('recipient_id', $user->id)
            ->whereNull('recipient_character_id')
            ->first();

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

        // Follow-back is a human friendship affordance. Persona audience edges
        // are deliberately excluded from both directions.
        $incoming = FollowRequest::query()
            ->where('requester_id', $user->id)
            ->where('recipient_id', $current->id)
            ->whereNull('recipient_character_id')
            ->where('status', FollowRequest::STATUS_ACCEPTED)
            ->exists();

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
     * profile-as-container view. Separate personas are omitted for everyone
     * except their owner and admins: enumerating one here would reveal the
     * persona-to-owner relationship even if every content item stayed hidden.
     * Linked personas remain listed regardless of their audience because their
     * owner association is intentionally public.
     *
     * @return list<array<string, mixed>>
     */
    private function charactersStrip(User $user, ?User $viewer): array
    {
        return $user->characters()
            ->when(
                ! $viewer instanceof User || ($viewer->id !== $user->id && ! $viewer->isAdmin()),
                fn ($query) => $query->where('is_linked', true),
            )
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

        // A persona edge to this owner does not subsume the human request.
        $followRequest = FollowRequest::query()
            ->where('requester_id', $current->id)
            ->where('recipient_id', $user->id)
            ->whereNull('recipient_character_id')
            ->first();
        $followRequest ??= new FollowRequest([
            'requester_id' => $current->id,
            'recipient_id' => $user->id,
        ]);

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

    /**
     * Follow one visible persona as an audience edge. Unlike human friendship
     * requests, persona follows are accepted immediately and never enter the
     * recipient's request inbox.
     */
    public function followCharacter(Request $request, Character $character): JsonResponse
    {
        $current = $request->user();
        $character->loadMissing('user');
        $owner = $character->user;

        if (! $current instanceof User || ! $owner instanceof User || ! $character->isViewableBy($current)) {
            abort(404, 'Not found.');
        }

        if ($current->is($owner)) {
            return response()->json(['success' => false, 'message' => 'You cannot follow your own persona.'], 422);
        }

        $followRequest = FollowRequest::query()->firstOrCreate(
            [
                'requester_id' => $current->id,
                'recipient_id' => $owner->id,
                'recipient_character_id' => $character->id,
            ],
            [
                'status' => FollowRequest::STATUS_ACCEPTED,
                'responded_at' => now(),
            ],
        );

        if (! $followRequest->wasRecentlyCreated) {
            return response()->json(['success' => false, 'message' => 'A persona follow already exists.'], 422);
        }

        $this->audit($followRequest, $current, 'followed');

        return response()->json([
            'success' => true,
            'data' => [
                'status' => FollowRequest::STATUS_ACCEPTED,
            ],
        ], 201);
    }

    /**
     * Followers of a visible persona, including account followers when the
     * persona is Linked. FollowGraph owns that subsumption rule; this endpoint
     * renders each requester once without exposing which qualifying edge they
     * used. The oldest edge supplies followed_at when both edge scopes exist.
     */
    public function characterFollowers(Request $request, Character $character): JsonResponse
    {
        $character->loadMissing(['user', 'profilePicture']);
        $current = $this->viewAs->viewerFor($request, $character->user, $character);
        if (! $character->isViewableBy($current)) {
            abort(404, 'Not found.');
        }

        $followers = FollowGraph::followersOfIdentity($character->user_id, $character->id)
            ->with([
                'requester:id,name,display_name,profile_audience,profile_picture_media_id',
                'requester.profilePicture',
            ])
            // Public output must stay fail-closed even if a legacy/imported row
            // violates the write endpoints' self-follow rejection.
            ->where('requester_id', '!=', $character->user_id)
            ->whereHas('requester', fn ($query) => $query->active())
            ->oldest('responded_at')
            ->oldest('id')
            ->get()
            ->unique('requester_id')
            ->values();

        $requesters = $followers->pluck('requester')->filter()->values();
        $canView = $this->gate->canViewMany($current, $requesters);

        return response()->json(['success' => true, 'data' => [
            'count' => $followers->count(),
            'viewer_is_following' => FollowGraph::followsIdentity(
                $current->id,
                $character->user_id,
                $character->id,
            ),
            'followers' => $followers->map(function (FollowRequest $followRequest) use ($canView, $current): array {
                $follower = $followRequest->requester;
                $visible = $follower instanceof User && ($canView[$follower->id] ?? false);

                return [
                    'follower' => [
                        'id' => $follower?->id,
                        'display_name' => $follower?->display_name ?: $follower?->name,
                        'avatar_url' => UserPresenter::avatarUrl($follower, $this->mediaResponder, $current),
                        'restricted' => ! $visible,
                    ],
                    'followed_at' => $followRequest->responded_at?->toIso8601String(),
                ];
            })->values(),
        ]]);
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
            ->where('recipient_id', $current?->id)
            ->whereNull('recipient_character_id')
            ->where('status', FollowRequest::STATUS_PENDING)
            ->latest()
            ->get();

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
            ->where('recipient_id', $request->user()?->id)
            ->whereNull('recipient_character_id')
            ->where('status', FollowRequest::STATUS_PENDING)
            ->count()]]);
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
        if (! $current instanceof User
            || $followRequest->recipient_id !== $current->id
            || $followRequest->recipient_character_id !== null
            || $followRequest->status !== FollowRequest::STATUS_PENDING) {
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
            'metadata' => $followRequest->recipient_character_id === null
                ? null
                : ['recipient_character_id' => $followRequest->recipient_character_id],
        ]);
    }
}
