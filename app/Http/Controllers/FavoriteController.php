<?php

namespace App\Http\Controllers;

use App\Http\Requests\Favorite\StoreFavoriteRequest;
use App\Models\Favorite;
use App\Models\User;
use App\Services\Favorites\FavoriteService;
use App\Services\Privacy\ProfileGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function __construct(
        private readonly FavoriteService $favorites,
        private readonly ProfileGate $profileGate,
    ) {}

    /**
     * Add the given item to the current user's favorites. The viewer must be able
     * to see the item (its own privacy decides that) — you cannot favorite what
     * you could not reach.
     */
    public function store(StoreFavoriteRequest $request): JsonResponse
    {
        $user = $request->user();
        $item = $this->favorites->resolve($request->validated('type'), (int) $request->validated('id'));

        if ($item === null) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        if (! $this->favorites->canViewerSee($user, $item)) {
            return response()->json(['success' => false, 'message' => 'You cannot favorite this.'], 403);
        }

        Favorite::query()->firstOrCreate([
            'user_id' => $user->id,
            'favoritable_type' => $item->getMorphClass(),
            'favoritable_id' => $item->getKey(),
        ]);

        return response()->json([
            'success' => true,
            'data' => ['favorited' => true, 'favorite' => $this->favorites->present($item, $user)],
        ], 201);
    }

    /**
     * Remove the given item from the current user's favorites.
     */
    public function destroy(StoreFavoriteRequest $request): JsonResponse
    {
        $user = $request->user();
        $item = $this->favorites->resolve($request->validated('type'), (int) $request->validated('id'));

        if ($item === null) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        Favorite::query()
            ->where('user_id', $user->id)
            ->where('favoritable_type', $item->getMorphClass())
            ->where('favoritable_id', $item->getKey())
            ->delete();

        return response()->json(['success' => true, 'data' => ['favorited' => false]]);
    }

    /**
     * The favorites of {user} that the current viewer may see. Gated first on
     * profile visibility (you must be able to view the profile at all), then each
     * item is filtered by its own privacy.
     */
    public function index(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();

        if (! $viewer instanceof User || ! $this->profileGate->canView($viewer, $user)) {
            return response()->json(['success' => false, 'message' => 'Profile unavailable.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->favorites->visibleFor($user, $viewer),
        ]);
    }
}
