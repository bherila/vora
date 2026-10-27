<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\ProviderAccounts;
use BWH\Auth\Contracts\AuthAuditLogger;
use BWH\Auth\Contracts\AuthUserPolicy;
use BWH\Auth\OAuth\OAuthClient;
use BWH\Auth\OAuth\Session\ProviderSession;
use BWH\Auth\OAuth\Session\ProviderStatusUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sign-in through the installation's identity provider (AUTH_SIGN_IN=provider).
 * The provider checks credentials; this application still decides, through
 * AuthUserPolicy, whether the account may proceed and where it lands.
 */
class ProviderLoginController extends Controller
{
    public function __construct(private readonly AuthAuditLogger $audit) {}

    public function callback(Request $request, OAuthClient $oauth, ProviderAccounts $accounts, AuthUserPolicy $policy): RedirectResponse
    {
        $identity = $oauth->identityFromCallback($request);
        $user = $accounts->resolve($identity);

        if (! $policy->canLogin($user, $request)) {
            $this->audit->loginFailed($request, $user, $user->email, 'account_disabled', 'oauth');
            abort(403, 'Your account here has been disabled.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        // The provider credential generation this sign-in was made under, which
        // every later status check compares against. A sign-in that cannot be
        // verified while enforcement is on is undone, and the person retries.
        try {
            app(ProviderSession::class)->establish($request, $identity, Auth::guard('web'));
        } catch (ProviderStatusUnavailable) {
            Auth::logout();
            abort(503, 'Sign-in verification is unavailable. Please try again shortly.', ['Retry-After' => '30']);
        }

        $this->audit->loginSucceeded($request, $user, 'oauth');

        return redirect($policy->redirectAfterLogin($user, $request));
    }
}
