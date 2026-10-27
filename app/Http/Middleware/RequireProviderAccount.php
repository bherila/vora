<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\SignIn;
use BWH\Auth\OAuth\Session\ProviderSession;
use BWH\Auth\OAuth\Session\ProviderSessionExpired;
use BWH\Auth\OAuth\Session\ProviderStatusUnavailable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Under provider sign-in, every signed-in session must belong to an account
 * bound to the configured provider, and (once enforcement is enabled) to an
 * identity the provider still reports active.
 *
 * The package's RequireActiveProviderSession re-asks the provider on every
 * unsafe request. Uploads here send many POSTs per file, which would exhaust
 * the provider's per-client status throttle, so ordinary requests share one
 * observation per person for its freshness window and only admin writes ask
 * again.
 */
class RequireProviderAccount
{
    /** The binding this session signed in through, set by the provider callback. */
    public const SESSION_KEY = 'provider_sign_in.binding';

    public function __construct(private readonly ProviderSession $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if (! $user instanceof User || ! SignIn::usesProvider() || $request->routeIs('logout')) {
            return $next($request);
        }

        // Sessions from before the switch, or an account bound elsewhere, end
        // here: they were not signed in through this provider. Binding an
        // account does not vouch for a password session it already had.
        if ($user->oauth_provider !== SignIn::provider() || ($user->oauth_subject ?? '') === ''
            || $request->session()->get(self::SESSION_KEY) !== [$user->oauth_provider, $user->oauth_subject]) {
            return $this->end($request);
        }

        if (config('bherila-auth.provider_identity.enabled', false)) {
            try {
                $this->sessions->assertActive($request, $user->oauth_provider, $user->oauth_subject,
                    fresh: ! $request->isMethodSafe() && $request->is('api/admin/*', 'admin/*'));
            } catch (ProviderSessionExpired) {
                return $this->end($request);
            } catch (ProviderStatusUnavailable) {
                abort(503, 'Sign-in verification is unavailable. Please try again shortly.', ['Retry-After' => '30']);
            }
        }

        return $next($request);
    }

    private function end(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson()
            ? response()->json(['message' => 'Please sign in again.'], 401)
            : redirect()->route('login');
    }
}
