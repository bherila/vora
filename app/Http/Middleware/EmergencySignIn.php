<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\EmergencyAccess;
use BWH\Auth\Contracts\AuthAuditLogger;
use BWH\Auth\Models\TwoFactorAttempt;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Keep the shared MFA implementation in the host realm and bind its challenge to this flow. */
final class EmergencySignIn
{
    public function __construct(private readonly EmergencyAccess $access, private readonly AuthAuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $access = $this->access;
        abort_unless($access->enabled(), 404);
        $overlay = [
            'bherila-auth.two_factor.route_name_prefix' => 'emergency.two-factor.',
            'bherila-auth.two_factor.login_url' => '/emergency-login',
            'bherila-auth.two_factor.session_user_key' => 'emergency_access.pending_user',
            'bherila-auth.two_factor.session_remember_key' => 'emergency_access.pending_remember',
            'bherila-auth.password_resets.request_url' => '/emergency-login',
        ];
        $previous = [];
        foreach ($overlay as $key => $value) {
            $previous[$key] = config($key);
        }
        config($overlay);
        try {
            $report = $request->routeIs('emergency.two-factor.report', 'emergency.two-factor.report.submit');
            if (! $report && ! $request->routeIs('emergency.show', 'emergency.store')) {
                $token = $request->route('token') ?? $request->input('attempt_token');
                $pending = $request->session()->get(EmergencyAccess::PENDING);
                $attempt = is_string($token) ? TwoFactorAttempt::where('token', $token)->first() : null;
                $user = $attempt?->user;
                $stored = $user instanceof User ? $access->eligible($user) : null;
                if ($stored === null || ! is_array($pending) || $pending !== [...$access->proof($stored), 'token' => $token]) {
                    $request->session()->forget(EmergencyAccess::PENDING);
                    $this->audit->twoFactorLoginFailed($request, $user instanceof User ? $user : null, $attempt, 'Emergency sign-in is no longer eligible.');
                    abort(422, 'Please start emergency sign-in again.');
                }
            }
            $response = $next($request);
            $user = Auth::guard('web')->user();
            if (! $report && $user instanceof User) {
                $stored = $access->eligible($user);
                if ($stored === null || ! isset($pending, $token) || $pending !== [...$access->proof($stored), 'token' => $token]) {
                    Auth::guard('web')->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                    abort(422, 'Please start emergency sign-in again.');
                }
                $request->session()->put(EmergencyAccess::SESSION, $access->proof($stored));
                $request->session()->forget(EmergencyAccess::PENDING);
            } elseif ($request->routeIs('emergency.resend') && $response instanceof JsonResponse && $response->isSuccessful()) {
                $pending = $request->session()->get(EmergencyAccess::PENDING);
                if (is_array($pending)) {
                    $pending['token'] = $response->getData(true)['attempt_token'];
                    $request->session()->put(EmergencyAccess::PENDING, $pending);
                }
            }

            return $response;
        } finally {
            config($previous);
        }
    }
}
