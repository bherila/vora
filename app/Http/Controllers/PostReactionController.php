<?php

namespace App\Http\Controllers;

use App\Http\Requests\Post\ReactionRequest;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Add/remove a reaction on a post. Gated by the post's view policy, so a viewer
 * can only react to a post they are allowed to see.
 */
class PostReactionController extends Controller
{
    public function store(ReactionRequest $request, Post $post): JsonResponse
    {
        Gate::authorize('view', $post);

        /** @var User $user */
        $user = $request->user();

        // Idempotent: reacting again is a no-op rather than a duplicate row.
        $post->reactions()->firstOrCreate([
            'user_id' => $user->id,
            'type' => $request->reactionType(),
        ]);

        return $this->summary($post, $user);
    }

    public function destroy(ReactionRequest $request, Post $post): JsonResponse
    {
        Gate::authorize('view', $post);

        /** @var User $user */
        $user = $request->user();

        $post->reactions()
            ->where('user_id', $user->id)
            ->where('type', $request->reactionType())
            ->delete();

        return $this->summary($post, $user);
    }

    private function summary(Post $post, User $user): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'reaction_count' => $post->reactions()->count(),
            'viewer_reacted' => $post->reactions()->where('user_id', $user->id)->exists(),
        ]]);
    }
}
