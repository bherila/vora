<?php

namespace App\Http\Controllers\Story;

use App\Http\Controllers\Controller;
use App\Http\Requests\Story\InviteCoAuthorRequest;
use App\Http\Requests\Story\UpdateStoryAuthorIdentityRequest;
use App\Models\Story;
use App\Models\StoryAuthor;
use App\Models\User;
use App\Notifications\CoAuthorInviteReceived;
use App\Services\Story\StoryService;
use App\Support\BlockGraph;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoryAuthorController extends Controller
{
    public function __construct(private readonly StoryService $stories) {}

    /**
     * List a story's authors and pending invites (any author may view).
     */
    public function index(Story $story): JsonResponse
    {
        Gate::authorize('update', $story);

        return response()->json(['success' => true, 'data' => $this->authorsPayload($story)]);
    }

    /**
     * Invite another user to co-author (owner only).
     */
    public function invite(InviteCoAuthorRequest $request, Story $story): JsonResponse
    {
        Gate::authorize('manageAuthors', $story);

        $owner = $request->user();
        $invitee = User::query()->find((int) $request->validated('user_id'));

        if (! $invitee instanceof User || ! $this->isInvitable($invitee)) {
            return response()->json(['success' => false, 'message' => 'This user cannot be invited.'], 422);
        }
        if (! $owner instanceof User || ! BlockGraph::canViewIdentity($owner, $invitee->id)) {
            return response()->json(['success' => false, 'message' => 'This user cannot be invited.'], 422);
        }

        if ($invitee->id === $story->user_id) {
            return response()->json(['success' => false, 'message' => 'You already own this story.'], 422);
        }

        if ($story->authors()->where('user_id', $invitee->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'This user is already an author or has a pending invite.'], 422);
        }

        $author = $story->authors()->create([
            'user_id' => $invitee->id,
            'invited_by_user_id' => $owner?->id,
            'role' => StoryAuthor::ROLE_CO_AUTHOR,
            'status' => StoryAuthor::STATUS_PENDING,
        ]);

        $invitee->notify(new CoAuthorInviteReceived($author->load(['story', 'invitedBy'])));

        return response()->json(['success' => true, 'data' => $this->authorsPayload($story)], 201);
    }

    /**
     * Select how the current author is credited on this story.
     */
    public function update(UpdateStoryAuthorIdentityRequest $request, Story $story, User $user): JsonResponse
    {
        $author = $story->authors()
            ->where('user_id', $user->id)
            ->where('status', StoryAuthor::STATUS_ACCEPTED)
            ->first();

        if (! $author instanceof StoryAuthor) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $author->character_id = $request->validated('character_id');
        $author->save();

        return response()->json(['success' => true, 'data' => $this->authorsPayload($story->refresh())]);
    }

    /**
     * Remove a co-author. The owner may remove any co-author; a co-author may
     * remove themselves (leave). The owner cannot be removed.
     */
    public function destroy(Story $story, User $user): JsonResponse
    {
        // Authorize before any row lookup so non-authors get a uniform 403 and
        // cannot probe who authors a private/draft story. Owners and accepted
        // co-authors both satisfy the `update` ability.
        Gate::authorize('update', $story);

        $current = request()->user();
        if (! $current instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $author = $story->authors()->where('user_id', $user->id)->first();
        if (! $author instanceof StoryAuthor) {
            return response()->json(['success' => false, 'message' => 'Not an author of this story.'], 404);
        }

        if ($author->isOwner()) {
            return response()->json(['success' => false, 'message' => 'The owner cannot be removed.'], 422);
        }

        $isOwner = $story->user_id === $current->id;
        $isSelf = $user->id === $current->id;
        if (! $isOwner && ! $isSelf) {
            return response()->json(['success' => false, 'message' => 'Not allowed.'], 403);
        }

        $author->delete();

        // The removed author's user/character "involves" tags are no longer
        // permitted; drop them now instead of leaving them until a details save.
        $this->stories->pruneDisallowedInvolvements($story);

        return response()->json(['success' => true, 'data' => $this->authorsPayload($story->refresh())]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function authorsPayload(Story $story): array
    {
        return $story->authors()->with(['user', 'character'])->get()->map(fn (StoryAuthor $author): array => [
            'id' => $author->id,
            'user_id' => $author->user_id,
            'character_id' => $author->character_id,
            'display_name' => $author->character?->display_name
                ?: ($author->user?->display_name ?: $author->user?->name),
            'role' => $author->role,
            'status' => $author->status,
            'is_owner' => $author->isOwner(),
        ])->values()->all();
    }

    private function isInvitable(User $user): bool
    {
        return $user->approved_at !== null && ! $user->is_disabled && ! $user->isDeactivated();
    }
}
