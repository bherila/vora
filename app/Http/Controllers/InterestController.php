<?php

namespace App\Http\Controllers;

use App\Http\Requests\Interest\BatchRateInterestRequest;
use App\Http\Requests\Interest\RequestInterestRequest;
use App\Http\Requests\Interest\SetInterestInheritanceRequest;
use App\Models\Character;
use App\Models\Interest;
use App\Models\InterestRating;
use App\Models\InterestRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterestController extends Controller
{
    /**
     * JSON list of interests with the ratings for a target profile. The target
     * is the logged-in user, or one of their characters when `character_id` is
     * supplied. Cross-user (or unknown) characters are hidden as 404.
     */
    public function apiIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $characterId = $request->query('character_id');
        $character = null;

        if ($characterId !== null) {
            $character = Character::query()->find($characterId);
            if ($character === null || $character->user_id !== $user->id) {
                return response()->json(['success' => false, 'message' => 'Not found.'], 404);
            }
        }

        // where('character_id', null) is translated to whereNull() by the query
        // builder, so this scopes to the user's own ratings when no character.
        $ratings = InterestRating::query()
            ->where('user_id', $user->id)
            ->where('character_id', $character?->id)
            ->pluck('level', 'interest_id');

        $interests = Interest::query()
            ->orderBy('parent_interest_id')
            ->orderBy('name')
            ->get()
            ->map(fn (Interest $interest): array => [
                'id' => $interest->id,
                'name' => $interest->name,
                'description' => $interest->description,
                'parent_interest_id' => $interest->parent_interest_id,
                'rating' => $ratings->has($interest->id) ? (int) $ratings[$interest->id] : null,
            ]);

        return response()->json([
            'success' => true,
            'inherit_interests' => $character?->inherit_interests ?? false,
            'data' => $interests,
        ]);
    }

    /**
     * Set or clear multiple interest ratings in one request for a target
     * profile (the user, or one of their characters). A null level clears a
     * rating; any other value upserts it.
     */
    public function batchRate(BatchRateInterestRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $characterId = $validated['character_id'] ?? null;
        $hasExplicit = false;

        foreach ($validated['ratings'] as $rating) {
            $interestId = (int) $rating['interest_id'];

            if ($rating['level'] === null) {
                InterestRating::query()
                    ->where('user_id', $user->id)
                    ->where('character_id', $characterId)
                    ->where('interest_id', $interestId)
                    ->delete();

                continue;
            }

            $hasExplicit = true;
            InterestRating::query()->updateOrCreate(
                ['user_id' => $user->id, 'character_id' => $characterId, 'interest_id' => $interestId],
                ['level' => (int) $rating['level']],
            );
        }

        // Persisting an explicit rating means the character is overriding, not
        // inheriting, the owner's profile interests.
        if ($characterId !== null && $hasExplicit) {
            Character::query()->whereKey($characterId)->update(['inherit_interests' => false]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Toggle whether a character inherits the owner's profile interests.
     * Switching inheritance on clears the character's own overrides.
     */
    public function setInheritance(SetInterestInheritanceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $character = Character::query()->findOrFail($validated['character_id']);
        $inherit = (bool) $validated['inherit'];

        $character->inherit_interests = $inherit;
        $character->save();

        if ($inherit) {
            $character->interestRatings()->delete();
        }

        return response()->json([
            'success' => true,
            'data' => ['character_id' => $character->id, 'inherit_interests' => $inherit],
        ]);
    }

    public function requestNew(RequestInterestRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validated();
        $data['user_id'] = $user->id;

        $interestRequest = InterestRequest::query()->create($data);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $interestRequest->id,
                'name' => $interestRequest->name,
                'description' => $interestRequest->description,
                'parent_interest_id' => $interestRequest->parent_interest_id,
                'status' => $interestRequest->status,
                'created_at' => $interestRequest->created_at?->toIso8601String(),
            ],
        ]);
    }
}
