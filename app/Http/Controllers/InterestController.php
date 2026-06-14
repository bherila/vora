<?php

namespace App\Http\Controllers;

use App\Http\Requests\Interest\RateInterestRequest;
use App\Models\Interest;
use App\Models\InterestRating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InterestController extends Controller
{
    /**
     * User-facing interest rating page.
     */
    public function index(): View
    {
        return view('user.interests');
    }

    /**
     * JSON list of interests for approved users to browse + rate.
     */
    public function apiIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $ratings = InterestRating::query()
            ->where('user_id', $user->id)
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
            'data' => $interests,
        ]);
    }

    public function rate(RateInterestRequest $request, Interest $interest): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $level = (int) $request->validated()['level'];

        InterestRating::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'interest_id' => $interest->id,
            ],
            [
                'level' => $level,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'interest_id' => $interest->id,
                'level' => $level,
            ],
        ]);
    }

    public function destroyRate(Request $request, Interest $interest): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }
        
        InterestRating::query()
            ->where('user_id', $user->id)
            ->where('interest_id', $interest->id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Interest rating removed.',
        ]);
    }
}
