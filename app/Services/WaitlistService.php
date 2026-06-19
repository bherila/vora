<?php

namespace App\Services;

use App\Mail\WaitlistInviteMail;
use App\Mail\WaitlistVerificationMail;
use App\Models\Invite;
use App\Models\User;
use App\Models\WaitlistRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Owns the public "request an invitation" waitlist flow: capturing a request
 * with its Cloudflare IP/geo, proving email ownership via a link/code, and an
 * admin admitting it into an auto-approving invite emailed to the requester.
 */
class WaitlistService
{
    /**
     * How long an admit-minted invite stays valid before the requester must be
     * re-admitted.
     */
    public const ADMIT_INVITE_LIFETIME_DAYS = 14;

    public function __construct(private readonly InviteService $invites) {}

    /**
     * Record (or refresh) a waitlist request for the given email and send the
     * verification email. Re-submitting for an email that still has a pending
     * (not-yet-admitted) request updates that row and re-issues its secrets
     * rather than creating a duplicate.
     *
     * @param  array{email: string, birth_date: string, interests: string}  $data
     * @param  array{ip: ?string, geo: array<string, string>}  $cf
     */
    public function submit(array $data, array $cf): WaitlistRequest
    {
        $code = $this->randomCode();
        $token = Str::random(40);

        $attributes = [
            'email' => $data['email'],
            'birth_date' => $data['birth_date'],
            'interests' => $data['interests'],
            'ip_address' => $cf['ip'] ?? null,
            'geo' => $cf['geo'] ?? [],
            'verification_code_hash' => Hash::make($code),
            'verification_token_hash' => Hash::make($token),
            'verified_at' => null,
        ];

        $request = WaitlistRequest::query()
            ->where('email', $data['email'])
            ->whereNull('admitted_at')
            ->latest('id')
            ->first();

        if ($request === null) {
            $request = new WaitlistRequest;
            $request->uuid = (string) Str::uuid();
        }

        $request->forceFill($attributes)->save();

        Mail::to($request->email)->send(new WaitlistVerificationMail($request, $token, $code));

        return $request;
    }

    /**
     * Verify email ownership by matching the secret against either the link token
     * or the typed code. Marks the request verified on first success.
     */
    public function verify(WaitlistRequest $request, string $secret): bool
    {
        if ($request->isVerified()) {
            return true;
        }

        $secret = trim($secret);

        if ($secret === '') {
            return false;
        }

        $matches = ($request->verification_token_hash !== null && Hash::check($secret, $request->verification_token_hash))
            || ($request->verification_code_hash !== null && Hash::check($secret, $request->verification_code_hash));

        if (! $matches) {
            return false;
        }

        $request->forceFill(['verified_at' => now()])->save();

        return true;
    }

    /**
     * Admit a request: mint an auto-approving invite bound to the verified email
     * and email the invite link to the requester.
     */
    public function admit(WaitlistRequest $request, User $admin): Invite
    {
        return DB::transaction(function () use ($request, $admin): Invite {
            $invite = $this->invites->createDirectInvite(
                $admin,
                $request->email,
                true,
                now()->addDays(self::ADMIT_INVITE_LIFETIME_DAYS),
            );

            $request->forceFill([
                'admitted_at' => now(),
                'admitted_by_user_id' => $admin->id,
                'invite_id' => $invite->id,
            ])->save();

            Mail::to($request->email)->send(new WaitlistInviteMail($invite));

            return $invite;
        });
    }

    private function randomCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
