<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates banned accounts to the ban notice. They remain logged in but can only
 * view the ban, submit an appeal, deactivate, or delete their account (subject
 * to a legal hold). Account-management endpoints, logout, and the public legal
 * pages are exempt so those flows stay reachable from the ban page.
 */
class EnsureNotBanned
{
    private const ALLOWED_ROUTES = [
        'account.banned',
        'account.appeal',
        'account.deactivated',
        'account.reactivate',
        'logout',
        'privacy',
        'terms',
        'pages.show',
    ];

    /**
     * Path prefixes for the JSON account actions a banned user may still call
     * (deactivate, delete, appeal). These have no route names.
     */
    private const ALLOWED_PATHS = [
        'api/account/deactivate',
        'api/account/delete',
        'api/account/appeal',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isBanned() && ! $this->isAllowed($request)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account is banned. You can appeal, deactivate, or delete your account.',
                ], 403);
            }

            return redirect()->route('account.banned');
        }

        return $next($request);
    }

    private function isAllowed(Request $request): bool
    {
        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return true;
        }

        foreach (self::ALLOWED_PATHS as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }
}
