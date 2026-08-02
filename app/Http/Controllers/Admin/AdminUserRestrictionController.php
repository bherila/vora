<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RestrictionCapability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRestrictionRequest;
use App\Models\User;
use App\Models\UserRestriction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserRestrictionController extends Controller
{
    public function index(User $user): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $user->restrictions()->latest()->get()->map(
                fn (UserRestriction $restriction): array => $this->present($restriction),
            )->values(),
        ]);
    }

    public function store(StoreUserRestrictionRequest $request, User $user): JsonResponse
    {
        $restriction = $user->restrictions()->create([
            'capability' => RestrictionCapability::from($request->validated('capability')),
            'restricted_by_user_id' => $request->user()?->id,
            'reason' => $request->validated('reason'),
            'expires_at' => $request->validated('expires_at'),
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->present($restriction),
        ], 201);
    }

    public function destroy(Request $request, User $user, UserRestriction $restriction): JsonResponse
    {
        if ($restriction->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        if ($restriction->lifted_at === null) {
            $restriction->forceFill([
                'lifted_at' => now(),
                'lifted_by_user_id' => $request->user()?->id,
            ])->save();
        }

        return response()->json([
            'success' => true,
            'data' => $this->present($restriction->refresh()),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(UserRestriction $restriction): array
    {
        return [
            'id' => $restriction->id,
            'capability' => $restriction->capability->value,
            'label' => $restriction->capability->label(),
            'reason' => $restriction->reason,
            'expires_at' => $restriction->expires_at?->toIso8601String(),
            'lifted_at' => $restriction->lifted_at?->toIso8601String(),
            'restricted_by_user_id' => $restriction->restricted_by_user_id,
            'lifted_by_user_id' => $restriction->lifted_by_user_id,
            'created_at' => $restriction->created_at?->toIso8601String(),
            'active' => $restriction->isActive(),
        ];
    }
}
