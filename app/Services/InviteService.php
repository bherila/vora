<?php

namespace App\Services;

use App\Models\Invite;
use App\Models\InviteGrant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Owns the two-layer invite model:
 *  - grants: admin-issued balances of invites with an admin-set expiry;
 *  - invites: single-use shareable links a user generates from their balance,
 *    valid for at least 72h from generation.
 *
 * All mutations run in transactions with row locks so a balance can't be
 * over-drawn and an invite can't be double-spent under concurrency.
 */
class InviteService
{
    /**
     * A generated invite link is valid for at least this long, even if the
     * grant it was drawn from expires sooner.
     */
    public const MINIMUM_GENERATED_LIFETIME_HOURS = 72;

    /**
     * Sum of remaining invites across the user's non-expired grants.
     */
    public function availableBalance(User $user): int
    {
        return (int) $this->activeGrantsQuery($user)->sum('remaining');
    }

    /**
     * Issue (or top up) a grant of `quantity` invites to a single user. No-op
     * for users the admin has barred from receiving invites.
     */
    public function issueGrant(User $holder, int $quantity, ?CarbonInterface $expiresAt, ?User $issuedBy = null): ?InviteGrant
    {
        if ($quantity < 1 || ! $holder->can_receive_invites) {
            return null;
        }

        return InviteGrant::create([
            'user_id' => $holder->id,
            'quantity' => $quantity,
            'remaining' => $quantity,
            'expires_at' => $expiresAt,
            'issued_by_user_id' => $issuedBy?->id,
        ]);
    }

    /**
     * Issue `quantity` invites to every user permitted to receive them.
     *
     * @return int Number of users granted.
     */
    public function issueToAll(int $quantity, ?CarbonInterface $expiresAt, User $issuedBy): int
    {
        if ($quantity < 1) {
            return 0;
        }

        $count = 0;

        User::query()
            ->where('can_receive_invites', true)
            ->whereNull('deactivated_at')
            ->where('is_disabled', false)
            ->whereNull('banned_at')
            ->chunkById(200, function ($users) use ($quantity, $expiresAt, $issuedBy, &$count): void {
                foreach ($users as $user) {
                    if ($this->issueGrant($user, $quantity, $expiresAt, $issuedBy) !== null) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * Generate a single-use invite link for the user, drawing from their
     * soonest-expiring active grant. The link's effective expiry is the later of
     * the grant's expiry and now + 72h (never-expiring grant → never-expiring link).
     *
     * @throws RuntimeException when the user has no invites available.
     */
    public function generate(User $user): Invite
    {
        return DB::transaction(function () use ($user): Invite {
            $grant = $this->activeGrantsQuery($user)
                ->orderByRaw('expires_at is null, expires_at asc')
                ->lockForUpdate()
                ->first();

            if ($grant === null) {
                throw new RuntimeException('No invites available.');
            }

            $grant->decrement('remaining');

            $floor = Carbon::now()->addHours(self::MINIMUM_GENERATED_LIFETIME_HOURS);
            $expiresAt = $grant->expires_at === null
                ? null
                : ($grant->expires_at->greaterThan($floor) ? $grant->expires_at : $floor);

            return Invite::create([
                'uuid' => (string) Str::uuid(),
                'inviter_user_id' => $user->id,
                'invite_grant_id' => $grant->id,
                'expires_at' => $expiresAt,
            ]);
        });
    }

    /**
     * Create a grant-free invite directly (no balance drawn). Used when an admin
     * admits a waitlist request: the invite is bound to the requester's verified
     * email and flagged `auto_approve` so the resulting account skips the approval
     * gate the admin has already satisfied.
     */
    public function createDirectInvite(User $inviter, ?string $email, bool $autoApprove, ?CarbonInterface $expiresAt): Invite
    {
        return Invite::create([
            'uuid' => (string) Str::uuid(),
            'inviter_user_id' => $inviter->id,
            'invite_grant_id' => null,
            'expires_at' => $expiresAt,
            'auto_approve' => $autoApprove,
            'email' => $email,
        ]);
    }

    /**
     * Resolve a redeemable invite by uuid: unused, unrevoked, unexpired, and
     * whose inviter is still an active account. Banning/disabling an inviter
     * therefore halts tree growth through their outstanding links.
     */
    public function findUsable(?string $uuid): ?Invite
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $invite = Invite::query()->with('inviter')->where('uuid', $uuid)->first();

        if ($invite === null || ! $invite->isUsable()) {
            return null;
        }

        $inviter = $invite->inviter;

        if ($inviter === null || ! $inviter->isActive() || $inviter->isBanned()) {
            return null;
        }

        return $invite;
    }

    /**
     * Mark the invite consumed by the new account and record the referral link.
     * Re-checks usability under a lock to enforce single-use under concurrency.
     *
     * @throws RuntimeException when the invite was already consumed.
     */
    public function consume(Invite $invite, User $newUser): void
    {
        DB::transaction(function () use ($invite, $newUser): void {
            $locked = Invite::query()->lockForUpdate()->find($invite->id);

            if ($locked === null || ! $locked->isUsable()) {
                throw new RuntimeException('This invite is no longer valid.');
            }

            $locked->forceFill([
                'used_at' => Carbon::now(),
                'invited_user_id' => $newUser->id,
            ])->save();

            $newUser->forceFill(['referred_by_invite_id' => $locked->id])->save();
        });
    }

    /**
     * Active grants for a user: still has remaining balance and not past expiry.
     *
     * @return Builder<InviteGrant>
     */
    private function activeGrantsQuery(User $user)
    {
        return InviteGrant::query()
            ->where('user_id', $user->id)
            ->where('remaining', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()));
    }
}
