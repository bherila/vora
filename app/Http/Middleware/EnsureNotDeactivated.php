<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates self-deactivated accounts to the reactivate page. They remain logged in
 * but cannot use the app (or Settings) until they reactivate. The reactivate
 * page/action, owned-data export and logout are exempt so the user can get back in,
 * retain a copy of their data or sign out, and
 * the public legal pages are exempt so the footer's Privacy/Terms links stay
 * reachable from the deactivated page.
 */
class EnsureNotDeactivated
{
    private const ALLOWED_ROUTES = [
        'account.deactivated',
        'account.reactivate',
        'account.export',
        'logout',
        'privacy',
        'terms',
        'pages.show',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isDeactivated()
            && ! in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account is deactivated. Reactivate it to continue.',
                ], 403);
            }

            return redirect()->route('account.deactivated');
        }

        return $next($request);
    }
}
