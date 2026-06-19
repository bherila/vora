<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\InviteService;
use App\Services\SettingsService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class RegisterController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly InviteService $invites,
    ) {}

    public function show(Request $request): View
    {
        $inviteUuid = $request->query('invite');
        $inviteUuid = is_string($inviteUuid) ? $inviteUuid : null;
        $invite = $this->invites->findUsable($inviteUuid);

        return view('auth.register', [
            'initialData' => ['register' => [
                'public_signups_enabled' => $this->settings->publicSignupsEnabled(),
                'invite' => $inviteUuid,
                'invite_valid' => $invite !== null,
                'inviter_name' => $invite?->inviter?->display_name,
                // Waitlist-admit invites are bound to the verified email; the form
                // locks the field to it so the address can't drift from the invite.
                'locked_email' => $invite?->email,
            ]],
        ]);
    }

    /**
     * Invite landing link (/i/{uuid}); forwards to the register page with the
     * invite prefilled so its validity is resolved and surfaced there.
     */
    public function landing(string $uuid): RedirectResponse
    {
        return redirect()->route('register', ['invite' => $uuid]);
    }

    public function store(RegisterRequest $request): RedirectResponse|JsonResponse
    {
        $data = $request->validated();

        // Count soft-deleted accounts too, so the first-user (auto-admin)
        // bootstrap can't re-trigger after every account has been soft-deleted.
        $isFirstUser = User::withTrashed()->count() === 0;

        // Resolve a usable invite (if any). The first-user bootstrap bypasses the
        // invite gate so the app can always be set up.
        $invite = $isFirstUser ? null : $this->invites->findUsable($data['invite'] ?? null);

        // When public signups are closed, a usable invite is mandatory.
        if (! $isFirstUser && ! $this->settings->publicSignupsEnabled() && $invite === null) {
            $message = 'Public sign-ups are currently closed. You need a valid invite link to join.';

            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $message], 422);
            }

            return redirect()->route('register')->withErrors(['invite' => $message])->withInput();
        }

        // The admin approval gate is skipped for the bootstrap user, a trusted
        // inviter's invitees, and waitlist-admit invites (the admin already vetted
        // the requester when admitting).
        $autoApprove = $isFirstUser
            || ($invite?->auto_approve === true)
            || ($invite?->inviter?->isTrustedInviter() === true);

        try {
            $user = DB::transaction(function () use ($data, $isFirstUser, $invite, $autoApprove): User {
                $user = new User;
                $user->name = $data['name'];
                $user->display_name = $data['display_name'];
                $user->birth_date = $data['birth_date'];
                // A bound invite locks the email to the address the waitlist
                // verified, so a tampered body can't redirect the invite.
                $user->email = $invite?->email ?? $data['email'];
                $user->password = $data['password']; // hashed via the model cast
                // Bootstrap: the very first account is an approved admin so the app is usable.
                if ($isFirstUser) {
                    $user->is_admin = true;
                }
                if ($autoApprove) {
                    $user->approved_at = now();
                    $user->approved_by_user_id = $invite?->inviter?->id;
                }
                // The waitlist already proved ownership of this exact address, so
                // carry the verification over instead of re-prompting for it.
                if ($invite?->email !== null) {
                    $user->email_verified_at = now();
                }
                $user->save();

                if ($invite !== null) {
                    $this->invites->consume($invite, $user);
                }

                // Seed brand-new accounts with the configured default invite balance.
                $defaultInvites = $this->settings->defaultNewUserInvites();
                if ($defaultInvites > 0) {
                    $expiryDays = $this->settings->defaultNewUserInviteExpiryDays();
                    $this->invites->issueGrant(
                        $user,
                        $defaultInvites,
                        $expiryDays !== null ? now()->addDays($expiryDays) : null,
                    );
                }

                return $user;
            });
        } catch (RuntimeException) {
            // Concurrency: another registration consumed this invite between
            // findUsable() and consume()'s locked re-check. The transaction rolled
            // back; surface the same stale-invite response as the up-front check.
            $message = 'This invite link is no longer valid. Ask your inviter for a new one.';

            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $message], 422);
            }

            return redirect()->route('register')->withErrors(['invite' => $message])->withInput();
        }

        $pending = ! $autoApprove;
        $signupRedirect = route('verification.notice', $pending ? ['signup_status' => 'pending-approval'] : []);

        event(new Registered($user));
        Auth::login($user);

        if ($request->expectsJson()) {
            $payload = [
                'success' => true,
                'redirect' => $signupRedirect,
            ];

            if ($pending) {
                $payload['status'] = 'Your account has been created and is pending admin approval.';
            }

            return response()->json($payload);
        }

        return redirect($signupRedirect);
    }
}
