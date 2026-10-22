<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\AgeEligibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAgeEligible
{
    public function __construct(private readonly AgeEligibility $eligibility) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // Existing admission gates retain their order; this gate also covers
        // asset routes and package APIs, including already-authenticated sessions.
        if (! $user instanceof User || ! $user->canLogin() || $user->isBanned() || $user->isDeactivated()
            || ! $user->hasVerifiedEmail() || ! $user->isApproved()
            || $this->eligibility->reason($user) === null || $this->allowed($request)) {
            return $next($request);
        }
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => 'Your account does not yet meet this site’s age requirements.', 'code' => 'age_eligibility_required', 'redirect' => '/age-eligibility'], 403);
        }

        return redirect()->route('age.eligibility');
    }

    private function allowed(Request $request): bool
    {
        if (in_array($request->route()?->getName(), ['age.eligibility', 'events.join', 'user.settings', 'logout', 'privacy', 'terms', 'pages.show', 'verification.notice', 'verification.verify', 'verification.send', 'approval.pending'], true)) {
            return true;
        }

        // Keep account security, export and removal available. This does not
        // exempt profile-photo uploads or any shared-content API.
        return $request->is('api/auth/*', 'api/change-password', 'api/passkeys', 'api/passkeys/*') || ($request->is('api/account') && $request->isMethod('PATCH'))
            || $request->is('api/account/export', 'api/account/storage', 'api/account/deactivate', 'api/account/delete');
    }
}
