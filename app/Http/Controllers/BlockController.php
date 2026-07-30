<?php

namespace App\Http\Controllers;

use App\Models\Block;
use App\Models\Character;
use App\Models\User;
use App\Services\Privacy\BlockService;
use App\Services\Privacy\ProfileGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlockController extends Controller
{
    public function __construct(
        private readonly BlockService $blocks,
        private readonly ProfileGate $profiles,
    ) {}

    public function blockUser(Request $request, string $blockUser): JsonResponse
    {
        $blocker = $request->user();
        $target = User::query()->whereKey($blockUser)->first();
        if (! $target instanceof User
            || ! $blocker instanceof User
            || $target->approved_at === null
            || ! $target->isActive()
            || ! $this->profiles->canView($blocker, $target)) {
            abort(404, 'Not found.');
        }
        if ($blocker->is($target)) {
            return response()->json(['success' => false, 'message' => 'You cannot block this user.'], 422);
        }

        $block = $this->blocks->block($blocker, $target);

        return response()->json([
            'success' => true,
            'data' => ['blocked' => true, 'block_id' => $block->id],
        ], $block->wasRecentlyCreated ? 201 : 200);
    }

    public function unblockUser(Request $request, User $user): JsonResponse
    {
        $blocker = $request->user();
        if (! $blocker instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $this->blocks->unblock($blocker, $user);

        return response()->json(['success' => true, 'data' => ['blocked' => false]]);
    }

    public function blockCharacter(Request $request, string $blockCharacter): JsonResponse
    {
        $blocker = $request->user();
        $target = Character::query()->whereKey($blockCharacter)->with('user')->first();
        if (! $target instanceof Character
            || ! $blocker instanceof User
            || ! $target->user instanceof User
            || ! $target->isViewableBy($blocker)) {
            abort(404, 'Not found.');
        }
        if ($blocker->is($target->user)) {
            return response()->json(['success' => false, 'message' => 'You cannot block this persona.'], 422);
        }

        $block = $this->blocks->block($blocker, $target->user, $target);

        return response()->json([
            'success' => true,
            'data' => ['blocked' => true, 'block_id' => $block->id],
        ], $block->wasRecentlyCreated ? 201 : 200);
    }

    public function unblockCharacter(Request $request, Character $character): JsonResponse
    {
        $blocker = $request->user();
        $character->loadMissing('user');
        if (! $blocker instanceof User || ! $character->user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $this->blocks->unblock($blocker, $character->user, $character);

        return response()->json(['success' => true, 'data' => ['blocked' => false]]);
    }

    /**
     * Unblock by the caller's own block row. This does not re-authorize the
     * target: a safety choice must remain reversible after that target changes
     * audience, deactivates, or deletes a persona.
     */
    public function destroy(Request $request, Block $block): JsonResponse
    {
        $blocker = $request->user();
        if (! $blocker instanceof User || $block->blocker_id !== $blocker->id) {
            abort(404, 'Not found.');
        }

        $this->blocks->remove($block);

        return response()->json(['success' => true, 'data' => ['blocked' => false]]);
    }
}
