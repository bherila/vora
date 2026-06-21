<?php

namespace App\Http\Controllers\Follow;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Story\AuthorshipInviteController;
use App\Models\FollowRequest;
use App\Models\FollowRequestAuditLog;
use App\Models\InterestRating;
use App\Models\User;
use App\Notifications\FollowRequestAccepted;
use App\Notifications\FollowRequestReceived;
use App\Services\Media\MediaResponseService;
use App\Services\Privacy\ProfileGate;
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

        $users = $query->with('profilePicture')->orderBy('display_name')->get();

        // Restricted profiles still appear in the directory so they remain
        // findable for a follow request, but their details are withheld from
        // viewers their audience tier doesn't admit.
        $canView = $current instanceof User ? $this->gate->canViewMany($current, $users) : [];

        return $users->map(function (User $user) use ($canView): array {
            $visible = $canView[$user->id] ?? false;

            return [
                'id' => $user->id,
                'display_name' => $user->display_name ?: $user->name,
                'avatar_url' => UserPresenter::avatarUrl($user, $this->mediaResponder),
                'restricted' => ! $visible,
                'user_type' => $visible ? $user->user_type : null,
                'gender' => $visible ? $user->gender : null,
            ];
        });
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

        // Always present, even on a restricted profile, so the viewer can still
        // send / track a follow request.
        $base = [
            'id' => $user->id,
            'display_name' => $user->display_name ?: $user->name,
            'avatar_url' => UserPresenter::avatarUrl($user, $this->mediaResponder),
            'follow_request' => $this->followRequestPayload($followRequest),
        ];

        if (! $this->gate->canView($current, $user)) {
            return $base + [
                'restricted' => true,
                'user_type' => null,
                'gender' => null,
                'mutual_interests' => [],
                'can_follow_back' => false,
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

        return $base + [
            'restricted' => false,
            'user_type' => $user->user_type,
            'gender' => $user->gender,
            'mutual_interests' => $mutualInterests,
            'can_follow_back' => $incoming && ($followRequest === null || $followRequest->status !== 'accepted'),
        ];
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
