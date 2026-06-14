<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the authenticated app area: users must have a verified email and an
 * admin-approved (non-disabled) account. Unmet users are redirected to the
 * relevant gate page rather than seeing app content.
 *
 * Do NOT apply this to the verification-notice or pending-approval pages
 * themselves — only to the protected app routes.
 */
class EnsureApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            if ($user->is_disabled) {
                auth()->logout();
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

            if (! $user->hasVerifiedEmail()) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Please verify your email address before continuing.',
                    ], 403);
                }

                return redirect()->route('verification.notice');
            }

            if ($user->isPendingApproval()) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Your account is pending admin approval.',
                    ], 403);
                }

                return redirect()->route('approval.pending', ['source' => 'login']);
            }
        }

        return $next($request);
    }
}
