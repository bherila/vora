<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of an admin-disabled account on every web request, not only
 * on `approved` routes: settings, account APIs and the package credential
 * endpoints sit outside that group. Logout is exempt so it can finish normally.
 */
class EnsureNotDisabled
{
    private const ALLOWED_ROUTES = ['logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_disabled
            || in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        auth()->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been disabled.',
            ], 403);
        }

        return redirect()->route('login')->withErrors([
            'email' => 'Your account has been disabled.',
        ]);
    }
}
