<?php

namespace App\Http\Controllers\Follow;

use App\Http\Controllers\Controller;
use App\Models\FollowRequest;
use App\Models\FollowRequestAuditLog;
use App\Models\InterestRating;
use App\Models\User;
use App\Notifications\FollowRequestAccepted;
use App\Notifications\FollowRequestReceived;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class FollowController extends Controller
{
    public function directory(): View { return view('user.follow-directory'); }
    public function profilePage(User $user): View { return view('user.follow-profile', ['profileUser' => $user]); }
    public function inboxPage(): View { return view('user.follow-requests'); }

    public function users(Request $request): JsonResponse
    {
        $current = $request->user();
        $users = User::query()->whereKeyNot($current?->id)->whereNotNull('approved_at')->where('is_disabled', false)->orderBy('display_name')->get();

        return response()->json(['success' => true, 'data' => $users->map(fn (User $user): array => [
            'id' => $user->id,
            'display_name' => $user->display_name ?: $user->name,
            'user_type' => $user->user_type,
            'gender' => $user->gender,
        ])]);
    }

    public function profile(Request $request, User $user): JsonResponse
    {
        $current = $request->user();
        if (! $current instanceof User || $current->is($user)) {
            return response()->json(['success' => false, 'message' => 'Profile unavailable.'], 404);
        }

        $currentInterestIds = InterestRating::query()->where('user_id', $current->id)->where('level', '>', 0)->pluck('interest_id');
        $mutualInterests = InterestRating::query()
            ->with('interest:id,name')
            ->where('user_id', $user->id)
            ->where('level', '>', 0)
            ->whereIn('interest_id', $currentInterestIds)
            ->get()
            ->map(fn (InterestRating $rating): array => ['id' => $rating->interest_id, 'name' => $rating->interest?->name]);

        $followRequest = FollowRequest::query()->where('requester_id', $current->id)->where('recipient_id', $user->id)->first();
        $incoming = FollowRequest::query()->where('requester_id', $user->id)->where('recipient_id', $current->id)->where('status', 'accepted')->exists();

        return response()->json(['success' => true, 'data' => [
            'id' => $user->id,
            'display_name' => $user->display_name ?: $user->name,
            'user_type' => $user->user_type,
            'gender' => $user->gender,
            'mutual_interests' => $mutualInterests,
            'follow_request' => $followRequest ? ['status' => $followRequest->status, 'updated_at' => $followRequest->updated_at?->toIso8601String()] : null,
            'can_follow_back' => $incoming && ($followRequest === null || $followRequest->status !== 'accepted'),
        ]]);
    }

    public function requestFollow(Request $request, User $user): JsonResponse
    {
        $current = $request->user();
        if (! $current instanceof User || $current->is($user)) {
            return response()->json(['success' => false, 'message' => 'You cannot follow this user.'], 422);
        }

        $followRequest = FollowRequest::query()->firstOrNew(['requester_id' => $current->id, 'recipient_id' => $user->id]);
        if ($followRequest->exists && $followRequest->status === 'declined' && $followRequest->responded_at?->gt(now()->subDay())) {
            return response()->json(['success' => false, 'message' => 'You can request again 24 hours after the request was declined.'], 429);
        }
        if ($followRequest->exists && in_array($followRequest->status, ['pending', 'accepted'], true)) {
            return response()->json(['success' => false, 'message' => 'A follow request already exists.'], 422);
        }

        $followRequest->status = 'pending';
        $followRequest->responded_at = null;
        $followRequest->save();
        $this->audit($followRequest, $current, 'requested');

        if ($user->email_follow_request_received) {
            $user->notify(new FollowRequestReceived($followRequest->load('requester')));
        }

        return response()->json(['success' => true, 'data' => ['status' => 'pending']]);
    }

    public function inbox(Request $request): JsonResponse
    {
        $current = $request->user();
        $requests = FollowRequest::query()->with('requester:id,name,display_name,user_type,gender')->where('recipient_id', $current?->id)->where('status', 'pending')->latest()->get();

        return response()->json(['success' => true, 'data' => $requests->map(fn (FollowRequest $followRequest): array => [
            'id' => $followRequest->id,
            'requester' => [
                'id' => $followRequest->requester?->id,
                'display_name' => $followRequest->requester?->display_name ?: $followRequest->requester?->name,
                'user_type' => $followRequest->requester?->user_type,
                'gender' => $followRequest->requester?->gender,
            ],
            'created_at' => $followRequest->created_at?->toIso8601String(),
        ])]);
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['count' => FollowRequest::query()->where('recipient_id', $request->user()?->id)->where('status', 'pending')->count()]]);
    }

    public function accept(Request $request, FollowRequest $followRequest): JsonResponse { return $this->decide($request, $followRequest, 'accepted'); }
    public function decline(Request $request, FollowRequest $followRequest): JsonResponse { return $this->decide($request, $followRequest, 'declined'); }

    private function decide(Request $request, FollowRequest $followRequest, string $status): JsonResponse
    {
        $current = $request->user();
        if (! $current instanceof User || $followRequest->recipient_id !== $current->id || $followRequest->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Follow request unavailable.'], 404);
        }

        $followRequest->status = $status;
        $followRequest->responded_at = Carbon::now();
        $followRequest->save();
        $this->audit($followRequest, $current, $status);

        if ($status === 'accepted' && $followRequest->requester?->email_follow_request_accepted) {
            $followRequest->requester->notify(new FollowRequestAccepted($followRequest->load('recipient')));
        }

        return response()->json(['success' => true, 'data' => ['status' => $status]]);
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
