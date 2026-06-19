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
            'registerBootstrap' => [
                'public_signups_enabled' => $this->settings->publicSignupsEnabled(),
                'invite' => $inviteUuid,
                'invite_valid' => $invite !== null,
                'inviter_name' => $invite?->inviter?->display_name,
            ],
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

        // A trusted inviter's invitees skip the admin approval gate.
        $autoApprove = $isFirstUser || ($invite?->inviter?->isTrustedInviter() === true);

        $user = DB::transaction(function () use ($data, $isFirstUser, $invite, $autoApprove): User {
            $user = new User;
            $user->name = $data['name'];
            $user->display_name = $data['display_name'];
            $user->birth_date = $data['birth_date'];
            $user->email = $data['email'];
            $user->password = $data['password']; // hashed via the model cast
            // Bootstrap: the very first account is an approved admin so the app is usable.
            if ($isFirstUser) {
                $user->is_admin = true;
            }
            if ($autoApprove) {
                $user->approved_at = now();
                $user->approved_by_user_id = $invite?->inviter?->id;
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
