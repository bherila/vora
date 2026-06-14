<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUserUpdateRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    /**
     * Admin users page (mounts the React admin UI).
     */
    public function index(): View
    {
        return view('admin.users');
    }

    /**
     * JSON list of users for the admin UI.
     */
    public function apiIndex(): JsonResponse
    {
        $users = User::query()
            ->orderByDesc('id')
            ->get(['id', 'name', 'email', 'is_admin', 'is_disabled', 'approved_at', 'email_verified_at', 'last_login_at', 'created_at'])
            ->map(fn (User $u): array => $this->present($u));

        return response()->json(['success' => true, 'data' => $users]);
    }

    /**
     * Approve a pending account.
     */
    public function approve(Request $request, User $user): JsonResponse
    {
        $user->forceFill([
            'approved_at' => now(),
            'approved_by_user_id' => $request->user()->id,
            'is_disabled' => false,
        ])->save();

        return response()->json(['success' => true, 'data' => $this->present($user)]);
    }

    /**
     * Toggle admin / disabled flags, with self-protection guards.
     */
    public function update(AdminUserUpdateRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        $actor = $request->user();

        if (array_key_exists('is_disabled', $data)) {
            if ($user->id === $actor->id || $user->id === 1) {
                return $this->forbidden('You cannot disable this account.');
            }
            $user->is_disabled = (bool) $data['is_disabled'];
        }

        if (array_key_exists('is_admin', $data)) {
            if ($user->id === 1) {
                return $this->forbidden('The primary admin cannot be modified.');
            }
            $user->is_admin = (bool) $data['is_admin'];
        }

        $user->save();

        return response()->json(['success' => true, 'data' => $this->present($user)]);
    }

    /**
     * Delete a user (never the actor or the primary admin).
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id || $user->id === 1) {
            return $this->forbidden('You cannot delete this account.');
        }

        $user->delete();

        return response()->json(['success' => true, 'message' => 'User deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => $user->isAdmin(),
            'is_disabled' => (bool) $user->is_disabled,
            'is_approved' => $user->isApproved(),
            'email_verified' => $user->hasVerifiedEmail(),
            'approved_at' => $user->approved_at?->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }
}
