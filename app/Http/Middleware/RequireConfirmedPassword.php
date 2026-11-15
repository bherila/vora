<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON-only password confirmation for credential enrollment. Laravel's
 * `password.confirm` redirects to a confirmation page this app does not have
 * whenever the request does not ask for JSON, which the package's passkey
 * client does not; this answers 423 instead, and POST /api/auth/confirm-password
 * records the confirmation in the session.
 */
class RequireConfirmedPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        if (now()->getTimestamp() - $confirmedAt < (int) config('auth.password_timeout', 10800)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Confirm your password to continue.',
            'error' => 'password_confirmation_required',
        ], 423);
    }
}
