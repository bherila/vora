<?php

namespace App\Http\Controllers\Auth;

use App\Http\Middleware\RequireProviderAccount;
use App\Models\ProviderInvitation;
use App\Models\User;
use App\Services\Auth\ProviderAccounts;
use App\Services\Auth\SignIn;
use BWH\Auth\Contracts\AuthUserPolicy;
use BWH\Auth\Http\Controllers\RelyingPartyLoginController;
use BWH\Auth\OAuth\Login\RelyingPartyAccounts;
use BWH\Auth\OAuth\OAuthIdentity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign-in and sign-out through the installation's identity provider
 * (AUTH_SIGN_IN=provider). The provider checks credentials; this application
 * still decides, through AuthUserPolicy, whether the account may proceed and
 * where it lands. The account rules are {@see ProviderAccounts}.
 *
 * `logout` also serves local sign-in, where it ends only the local session.
 */
class ProviderLoginController extends RelyingPartyLoginController
{
    protected function accounts(): RelyingPartyAccounts
    {
        return app(ProviderAccounts::class);
    }

    protected function landingUrl(Request $request, Authenticatable $account): string
    {
        return app(AuthUserPolicy::class)->redirectAfterLogin($account, $request);
    }

    protected function disabledAccountResponse(Request $request, Authenticatable $account): Response
    {
        abort(403, $account instanceof User && $account->trashed()
            ? 'This account has been deleted.'
            : 'Your account here has been disabled.');
    }

    protected function afterSignIn(Request $request, Authenticatable $account, OAuthIdentity $identity): void
    {
        if (! $account instanceof User) {
            return;
        }

        // The binding this session signed in through; RequireProviderAccount ends any other.
        $request->session()->put(RequireProviderAccount::SESSION_KEY, [$account->oauth_provider, $account->oauth_subject]);

        // Shown to whoever invited the address. The provider admits by link, not
        // address, so this is a courtesy and grants nothing.
        ProviderInvitation::query()->pendingTo($identity->provider, $identity->email)->update(['status' => ProviderInvitation::ACCEPTED]);
    }

    /** Under local sign-in there is no provider session to end. */
    protected function signsOutLocallyOnly(Request $request, ?Authenticatable $user): bool
    {
        return ! SignIn::usesProvider();
    }

    protected function afterSignOutUrl(Request $request): string
    {
        return SignIn::usesProvider() ? route('home') : route('login');
    }

    /**
     * A script caller cannot follow a redirect to the provider's origin, so it
     * gets the destination as JSON (see resources/js/auth/logout.ts).
     */
    protected function logoutResponse(Request $request, RedirectResponse $redirect): Response
    {
        return $request->expectsJson() && SignIn::usesProvider()
            ? response()->json(['redirect' => $redirect->getTargetUrl()])
            : parent::logoutResponse($request, $redirect);
    }
}
