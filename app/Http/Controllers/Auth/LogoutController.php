<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\SignIn;
use BWH\Auth\Concerns\SignsOutThroughProvider;
use BWH\Auth\Contracts\AuthAuditLogger;
use BWH\Auth\OAuth\OAuthClient;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ends the local session and, under provider sign-in, the provider's too:
 * otherwise the next sign-in would return the same person without a prompt.
 * Script callers get the destination as JSON, because a fetch cannot follow a
 * redirect to the provider's origin.
 */
class LogoutController extends Controller
{
    use SignsOutThroughProvider;

    public function __construct(private readonly AuthAuditLogger $audit) {}

    public function __invoke(Request $request, OAuthClient $oauth): RedirectResponse|JsonResponse
    {
        if (SignIn::usesProvider()) {
            $redirect = $this->signOutThroughProvider($request, $oauth, route('home'));

            return $request->expectsJson()
                ? response()->json(['redirect' => $redirect->getTargetUrl()])
                : $redirect;
        }

        $user = $request->user();
        Auth::logout();
        $this->audit->loggedOut($request, $user);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    protected function afterLocalSignOut(Request $request, ?Authenticatable $user): void
    {
        $this->audit->loggedOut($request, $user);
    }
}
