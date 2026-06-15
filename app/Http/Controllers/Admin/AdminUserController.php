<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUserUpdateRequest;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function __construct(private readonly UserAccountService $accounts) {}

    /**
     * Admin users page (mounts the React admin UI).
     */
    public function index(): View
    {
        return view('admin.users');
    }

    /**
     * JSON list of users for the admin UI. Includes soft-deleted accounts so an
     * admin can restore or permanently purge them.
     */
    public function apiIndex(): JsonResponse
    {
        $users = User::query()
            ->withTrashed()
            ->orderByDesc('id')
            ->get([
                'id',
                'name',
                'display_name',
                'birth_date',
                'email',
                'is_admin',
                'is_disabled',
                'approved_at',
                'deactivated_at',
                'deleted_at',
                'email_verified_at',
                'id_verified_at',
                'name_locked',
                'email_locked',
                'last_login_at',
                'created_at',
            ])
            ->map(fn (User $u): array => $this->present($u));

        return response()->json(['success' => true, 'data' => $users]);
    }

    /**
     * Approve a pending account.
     */
    public function approve(Request $request, User $user): JsonResponse
    {
        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Users must verify their email before they can be approved.',
            ], 422);
        }

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

        if (array_key_exists('name_locked', $data)) {
            $user->name_locked = (bool) $data['name_locked'];
        }

        if (array_key_exists('email_locked', $data)) {
            $user->email_locked = (bool) $data['email_locked'];
        }

        if (array_key_exists('id_verified', $data)) {
            $user->id_verified_at = (bool) $data['id_verified'] ? now() : null;
        }

        if (array_key_exists('birth_date', $data)) {
            $user->birth_date = $data['birth_date'];
        }

        $user->save();

        return response()->json(['success' => true, 'data' => $this->present($user)]);
    }

    /**
     * Permanently delete (purge) a user and all their media, characters, and
     * storage objects. Never the actor or the primary admin.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id || $user->id === 1) {
            return $this->forbidden('You cannot delete this account.');
        }

        $this->accounts->purge($user);

        return response()->json(['success' => true, 'message' => 'User permanently deleted.']);
    }

    /**
     * Restore a soft-deleted account.
     */
    public function restore(User $user): JsonResponse
    {
        if ($user->trashed()) {
            $user->restore();
        }

        return response()->json(['success' => true, 'data' => $this->present($user)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'display_name' => $user->display_name,
            'birth_date' => $user->birth_date?->toDateString(),
            'email' => $user->email,
            'is_admin' => $user->isAdmin(),
            'is_disabled' => (bool) $user->is_disabled,
            'is_deactivated' => $user->isDeactivated(),
            'is_deleted' => $user->trashed(),
            'deactivated_at' => $user->deactivated_at?->toIso8601String(),
            'deleted_at' => $user->deleted_at?->toIso8601String(),
            'is_approved' => $user->isApproved(),
            'email_verified' => $user->hasVerifiedEmail(),
            'id_verified' => $user->id_verified_at !== null,
            'birth_date_verified' => $user->id_verified_at !== null,
            'name_locked' => (bool) $user->name_locked,
            'email_locked' => (bool) $user->email_locked,
            'id_verified_at' => $user->id_verified_at?->toIso8601String(),
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
