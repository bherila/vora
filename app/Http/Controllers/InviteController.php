<?php

namespace App\Http\Controllers;

use App\Models\Invite;
use App\Models\User;
use App\Services\InviteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use RuntimeException;

class InviteController extends Controller
{
    public function __construct(private readonly InviteService $invites) {}

    /**
     * The user's invites page (mounts the React UI).
     */
    public function page(): View
    {
        /** @var User $user */
        $user = auth()->user();

        return view('user.invites', ['initialData' => [
            'invites' => $this->indexPayload($user),
        ]]);
    }

    /**
     * JSON: the user's available balance, soonest grant expiry, and the invite
     * links they have generated.
     */
    public function apiIndex(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        return response()->json([
            'success' => true,
            'data' => $this->indexPayload($user),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function indexPayload(User $user): array
    {
        $invites = $user->sentInvites()
            ->with('invitedUser:id,display_name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Invite $invite): array => $this->present($invite));

        $nextExpiry = $user->inviteGrants()
            ->where('remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', Carbon::now())
            ->min('expires_at');

        return [
            'balance' => $this->invites->availableBalance($user),
            'next_grant_expires_at' => $nextExpiry ? Carbon::parse($nextExpiry)->toIso8601String() : null,
            'invites' => $invites,
        ];
    }

    /**
     * Generate a new single-use invite link from the user's balance.
     */
    public function generate(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            $invite = $this->invites->generate($user);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->present($invite),
            'balance' => $this->invites->availableBalance($user),
        ], 201);
    }

    /**
     * Revoke one of the user's own unused invite links.
     */
    public function revoke(Invite $invite): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        if ($invite->inviter_user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        if ($invite->used_at !== null) {
            return response()->json(['success' => false, 'message' => 'This invite has already been used.'], 422);
        }

        if ($invite->revoked_at === null) {
            $invite->forceFill(['revoked_at' => Carbon::now()])->save();
        }

        return response()->json([
            'success' => true,
            'data' => $this->present($invite->refresh()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Invite $invite): array
    {
        return [
            'uuid' => $invite->uuid,
            'url' => route('invite.landing', $invite->uuid),
            'status' => $this->status($invite),
            'invited_user' => $invite->invitedUser?->display_name,
            'expires_at' => $invite->expires_at?->toIso8601String(),
            'used_at' => $invite->used_at?->toIso8601String(),
            'created_at' => $invite->created_at?->toIso8601String(),
        ];
    }

    private function status(Invite $invite): string
    {
        return match (true) {
            $invite->used_at !== null => 'used',
            $invite->revoked_at !== null => 'revoked',
            $invite->expires_at !== null && $invite->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }
}
