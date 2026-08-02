<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminBanUserRequest;
use App\Http\Requests\Admin\AdminIssueInvitesRequest;
use App\Http\Requests\Admin\AdminLegalHoldRequest;
use App\Http\Requests\Admin\AdminUserUpdateRequest;
use App\Models\InviteGrant;
use App\Models\User;
use App\Services\Chat\ChatState;
use App\Services\InviteService;
use App\Services\UserAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function __construct(
        private readonly UserAccountService $accounts,
        private readonly InviteService $invites,
        private readonly ChatState $chatState,
    ) {}

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
        $balances = $this->inviteBalances();

        $users = User::query()
            ->withTrashed()
            ->with(['referredByInvite.inviter:id,display_name', 'restrictions'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (User $u): array => $this->present($u, $balances[$u->id] ?? 0));

        return response()->json(['success' => true, 'data' => $users]);
    }

    /**
     * Available invite balance per user (sum of remaining over non-expired
     * grants), computed in a single query to avoid an N+1 over the user list.
     *
     * @return array<int, int>
     */
    private function inviteBalances(): array
    {
        return InviteGrant::query()
            ->where('remaining', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->selectRaw('user_id, SUM(remaining) as balance')
            ->groupBy('user_id')
            ->pluck('balance', 'user_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
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

        if (array_key_exists('can_receive_invites', $data)) {
            $user->can_receive_invites = (bool) $data['can_receive_invites'];
        }

        if (array_key_exists('trusted_inviter', $data)) {
            $user->trusted_inviter = (bool) $data['trusted_inviter'];
        }

        if (array_key_exists('birth_date', $data)) {
            $user->birth_date = $data['birth_date'];
        }

        $stateChanged = $user->isDirty('is_disabled');
        $user->save();
        if ($stateChanged) {
            $this->chatState->touchUserAndPeers($user);
        }

        return response()->json(['success' => true, 'data' => $this->present($user)]);
    }

    /**
     * Ban an account. Banned users can still log in but are gated to the ban
     * notice (appeal/deactivate/delete). `hide_content` also hides their content.
     */
    public function ban(AdminBanUserRequest $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if ($user->id === $actor->id || $user->id === 1) {
            return $this->forbidden('You cannot ban this account.');
        }

        $data = $request->validated();

        $user->forceFill([
            'banned_at' => now(),
            'banned_by_user_id' => $actor->id,
            'ban_reason' => $data['reason'] ?? null,
            'ban_hides_content' => (bool) ($data['hide_content'] ?? false),
        ])->save();
        $this->chatState->touchUserAndPeers($user);

        return response()->json(['success' => true, 'data' => $this->present($user)]);
    }

    /**
     * Lift a ban. Clears the ban state and any recorded appeal.
     */
    public function unban(User $user): JsonResponse
    {
        $user->forceFill([
            'banned_at' => null,
            'banned_by_user_id' => null,
            'ban_reason' => null,
            'ban_hides_content' => false,
            'ban_appeal_message' => null,
            'ban_appeal_at' => null,
        ])->save();
        $this->chatState->touchUserAndPeers($user);

        return response()->json(['success' => true, 'data' => $this->present($user)]);
    }

    /**
     * Place or lift a legal hold. Independent of the ban state; blocks the user
     * from deleting their account while active.
     */
    public function legalHold(AdminLegalHoldRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        if ((bool) $data['on_hold']) {
            $user->forceFill([
                'legal_hold_at' => now(),
                'legal_hold_by_user_id' => $request->user()->id,
                'legal_hold_note' => $data['note'] ?? null,
            ])->save();
        } else {
            $user->forceFill([
                'legal_hold_at' => null,
                'legal_hold_by_user_id' => null,
                'legal_hold_note' => null,
            ])->save();
        }

        return response()->json(['success' => true, 'data' => $this->present($user)]);
    }

    /**
     * Issue (top up) an invite grant to this specific user.
     */
    public function issueInvites(AdminIssueInvitesRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        $expiresAt = isset($data['expires_in_days']) && $data['expires_in_days'] !== null
            ? now()->addDays((int) $data['expires_in_days'])
            : null;

        $grant = $this->invites->issueGrant($user, (int) $data['quantity'], $expiresAt, $request->user());

        if ($grant === null) {
            return response()->json([
                'success' => false,
                'message' => 'This user cannot receive invites.',
            ], 422);
        }

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

        // A legal hold preserves the account and its data; it must block the admin
        // purge too, not just the user's self-service delete. Lift the hold first.
        if ($user->isOnLegalHold()) {
            return $this->forbidden('This account is under a legal hold and cannot be deleted. Lift the hold first.');
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
            $this->chatState->touchUserAndPeers($user);
        }

        return response()->json(['success' => true, 'data' => $this->present($user)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user, ?int $balance = null): array
    {
        $balance ??= $this->invites->availableBalance($user);
        $referrer = $user->referredByInvite?->inviter;

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

            // Invites & moderation.
            'invite_balance' => $balance,
            'can_receive_invites' => (bool) $user->can_receive_invites,
            'trusted_inviter' => (bool) $user->trusted_inviter,
            'is_banned' => $user->isBanned(),
            'banned_at' => $user->banned_at?->toIso8601String(),
            'ban_reason' => $user->ban_reason,
            'ban_hides_content' => (bool) $user->ban_hides_content,
            'ban_appeal_message' => $user->ban_appeal_message,
            'ban_appeal_at' => $user->ban_appeal_at?->toIso8601String(),
            'is_on_legal_hold' => $user->isOnLegalHold(),
            'legal_hold_note' => $user->legal_hold_note,
            'referrer_user_id' => $referrer?->id,
            'referrer_display_name' => $referrer?->display_name,
            'restrictions' => $user->restrictions
                ->sortByDesc('id')
                ->map(fn ($restriction): array => [
                    'id' => $restriction->id,
                    'capability' => $restriction->capability->value,
                    'label' => $restriction->capability->label(),
                    'reason' => $restriction->reason,
                    'expires_at' => $restriction->expires_at?->toIso8601String(),
                    'lifted_at' => $restriction->lifted_at?->toIso8601String(),
                    'created_at' => $restriction->created_at?->toIso8601String(),
                    'active' => $restriction->isActive(),
                ])
                ->values(),
        ];
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }
}
