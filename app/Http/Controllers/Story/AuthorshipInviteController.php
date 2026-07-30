<?php

namespace App\Http\Controllers\Story;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Follow\FollowController;
use App\Models\StoryAuthor;
use App\Models\User;
use App\Notifications\CoAuthorInviteAccepted;
use App\Support\BlockGraph;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The co-authorship side of the shared acceptance inbox. Mirrors the follow
 * request inbox so both kinds of pending request are accepted/declined from the
 * same place (see {@see FollowController}).
 */
class AuthorshipInviteController extends Controller
{
    /**
     * Pending co-author invitations addressed to the current user.
     */
    public function inbox(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->inboxPayload($request->user())]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function inboxPayload(?User $user): Collection
    {
        $invites = $this->pendingInvitesFor($user?->id)
            ->with(['story', 'invitedBy'])
            ->latest()
            ->get();

        return $invites->map(fn (StoryAuthor $invite): array => [
            'id' => $invite->id,
            'story' => [
                'id' => $invite->story?->id,
                'ulid' => $invite->story?->ulid,
                'title' => $invite->story?->title,
                'type' => $invite->story?->type->value,
            ],
            'invited_by' => $invite->invitedBy?->display_name ?: $invite->invitedBy?->name,
            'created_at' => $invite->created_at?->toIso8601String(),
        ]);
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'count' => $this->pendingInvitesFor($request->user()?->id)->count(),
        ]]);
    }

    /**
     * Pending invites for a user, excluding any whose story owner has since
     * deactivated, been disabled, or deleted their account (those can never be
     * accepted, so they must not show as actionable cards/badges).
     *
     * @return Builder<StoryAuthor>
     */
    private function pendingInvitesFor(?int $userId): Builder
    {
        $query = StoryAuthor::query()
            ->where('user_id', $userId)
            ->pendingForActiveOwner();

        if ($userId !== null) {
            $viewer = User::query()->find($userId);
            if ($viewer instanceof User) {
                $query->whereHas('story', function (Builder $stories) use ($viewer): void {
                    BlockGraph::visibleTo($stories, $viewer, 'stories.user_id');
                });
            }
        }

        return $query;
    }

    public function accept(Request $request, StoryAuthor $storyAuthor): JsonResponse
    {
        $current = $request->user();
        if (! $current instanceof User || $storyAuthor->user_id !== $current->id || $storyAuthor->status !== StoryAuthor::STATUS_PENDING) {
            return response()->json(['success' => false, 'message' => 'Invitation unavailable.'], 404);
        }

        // If the owner deactivated, was disabled, or deleted their account after
        // sending the invite, accepting would grant edit access to content owned
        // by an inactive account — mirror the follow-request inactive guard.
        $owner = $storyAuthor->story?->user;
        if (! $owner instanceof User || $owner->isDeactivated() || ! $owner->canLogin()) {
            return response()->json(['success' => false, 'message' => 'Invitation unavailable.'], 404);
        }
        if (! BlockGraph::canViewIdentity($current, $owner->id)) {
            return response()->json(['success' => false, 'message' => 'Invitation unavailable.'], 404);
        }

        $storyAuthor->status = StoryAuthor::STATUS_ACCEPTED;
        $storyAuthor->responded_at = Carbon::now();
        $storyAuthor->save();

        $owner = $storyAuthor->story?->user;
        if ($owner instanceof User) {
            $owner->notify(new CoAuthorInviteAccepted($storyAuthor->load(['story', 'user'])));
        }

        return response()->json(['success' => true, 'data' => ['status' => 'accepted']]);
    }

    /**
     * Declining removes the invitation. The owner can re-invite later.
     */
    public function decline(Request $request, StoryAuthor $storyAuthor): JsonResponse
    {
        $current = $request->user();
        if (! $current instanceof User || $storyAuthor->user_id !== $current->id || $storyAuthor->status !== StoryAuthor::STATUS_PENDING) {
            return response()->json(['success' => false, 'message' => 'Invitation unavailable.'], 404);
        }

        $storyAuthor->delete();

        return response()->json(['success' => true, 'data' => ['status' => 'declined']]);
    }
}
