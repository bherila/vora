<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminIssueInvitesRequest;
use App\Http\Requests\Admin\AdminSignupSettingsRequest;
use App\Models\Invite;
use App\Models\InviteGrant;
use App\Models\User;
use App\Services\InviteService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class AdminInviteController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly InviteService $invites,
    ) {}

    /**
     * Admin invites & signups page (mounts the React UI).
     */
    public function index(): View
    {
        return view('admin.invites');
    }

    /**
     * JSON: signup settings, per-user invite balances, the referral tree, and
     * recent invites.
     */
    public function apiIndex(): JsonResponse
    {
        $balances = $this->balances();

        // Nodes for the referral tree. referrer_user_id is the inviter (via the
        // invite the user redeemed); null = signed up without an invite (a root).
        // Include soft-deleted accounts so a deleted invitee (and its descendants)
        // stays linked in the chain instead of being promoted to spurious roots.
        $users = User::withTrashed()
            ->with('referredByInvite:id,inviter_user_id')
            ->orderBy('id')
            ->get(['id', 'display_name', 'trusted_inviter', 'banned_at', 'referred_by_invite_id'])
            ->map(fn (User $u): array => [
                'id' => $u->id,
                'display_name' => $u->display_name,
                'referrer_user_id' => $u->referredByInvite?->inviter_user_id,
                'trusted_inviter' => (bool) $u->trusted_inviter,
                'is_banned' => $u->banned_at !== null,
                'balance' => $balances[$u->id] ?? 0,
            ]);

        $recentInvites = Invite::query()
            ->with(['inviter:id,display_name', 'invitedUser:id,display_name'])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (Invite $invite): array => [
                'uuid' => $invite->uuid,
                'inviter' => $invite->inviter?->display_name,
                'invited_user' => $invite->invitedUser?->display_name,
                'status' => $this->inviteStatus($invite),
                'expires_at' => $invite->expires_at?->toIso8601String(),
                'created_at' => $invite->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'settings' => [
                    'public_signups_enabled' => $this->settings->publicSignupsEnabled(),
                    'default_new_user_invites' => $this->settings->defaultNewUserInvites(),
                    'default_new_user_invite_expiry_days' => $this->settings->defaultNewUserInviteExpiryDays(),
                ],
                'users' => $users,
                'recent_invites' => $recentInvites,
            ],
        ]);
    }

    /**
     * Update signup settings (toggle public signups, default new-user invites).
     */
    public function updateSettings(AdminSignupSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('public_signups_enabled', $data)) {
            $this->settings->set(SettingsService::PUBLIC_SIGNUPS_ENABLED, (bool) $data['public_signups_enabled']);
        }

        if (array_key_exists('default_new_user_invites', $data)) {
            $this->settings->set(SettingsService::DEFAULT_NEW_USER_INVITES, (int) $data['default_new_user_invites']);
        }

        if (array_key_exists('default_new_user_invite_expiry_days', $data)) {
            $this->settings->set(
                SettingsService::DEFAULT_NEW_USER_INVITE_EXPIRY_DAYS,
                $data['default_new_user_invite_expiry_days'] === null ? '' : (int) $data['default_new_user_invite_expiry_days'],
            );
        }

        return response()->json([
            'success' => true,
            'data' => [
                'public_signups_enabled' => $this->settings->publicSignupsEnabled(),
                'default_new_user_invites' => $this->settings->defaultNewUserInvites(),
                'default_new_user_invite_expiry_days' => $this->settings->defaultNewUserInviteExpiryDays(),
            ],
        ]);
    }

    /**
     * Issue invites to every user permitted to receive them.
     */
    public function issueToAll(AdminIssueInvitesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $expiresAt = isset($data['expires_in_days']) && $data['expires_in_days'] !== null
            ? now()->addDays((int) $data['expires_in_days'])
            : null;

        $count = $this->invites->issueToAll((int) $data['quantity'], $expiresAt, $request->user());

        return response()->json([
            'success' => true,
            'message' => "Issued {$data['quantity']} invite(s) to {$count} user(s).",
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function balances(): array
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

    private function inviteStatus(Invite $invite): string
    {
        return match (true) {
            $invite->used_at !== null => 'used',
            $invite->revoked_at !== null => 'revoked',
            $invite->expires_at !== null && $invite->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }
}
