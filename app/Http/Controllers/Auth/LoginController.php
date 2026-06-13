<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use BWH\Auth\Concerns\ThrottlesLoginAttempts;
use BWH\Auth\Contracts\AuthAuditLogger;
use BWH\Auth\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Password login is two-step: verify credentials, then issue an email 2FA challenge.
 * The shared bwh-auth TwoFactorForm posts the code to the package's verify endpoint,
 * which completes the session login. Passkey login is handled entirely by the package.
 */
class LoginController extends Controller
{
    use ThrottlesLoginAttempts;

    public function __construct(
        private readonly AuthAuditLogger $audit,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse|JsonResponse
    {
        $email = (string) $request->validated()['email'];

        $throttle = $this->inspectLoginThrottle($request, email: $email);
        if (! $throttle->allowsLogin()) {
            $this->auditLoginBlocked($request, email: $email, state: $throttle);

            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in '.$throttle->availableInSeconds().' seconds.',
            ]);
        }

        /** @var User|null $user */
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check((string) $request->validated()['password'], $user->password)) {
            $this->audit->loginFailed($request, $user, $email, 'invalid_credentials');

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (! $user->canLogin()) {
            $this->audit->loginFailed($request, $user, $email, 'account_disabled');

            throw ValidationException::withMessages([
                'email' => 'Your account has been disabled.',
            ]);
        }

        // Credentials valid — issue the email 2FA challenge. startChallenge() stores the
        // pending-user session keys the package's verify endpoint reads to complete login.
        $attempt = $this->twoFactor->startChallenge($user, $request, $request->boolean('remember'));

        if ($request->expectsJson()) {
            return response()->json(['requires_2fa' => true, 'attempt_token' => $attempt->token]);
        }

        return redirect()->route('login.two-factor', ['token' => $attempt->token]);
    }

    public function showTwoFactor(Request $request, string $token): View|RedirectResponse
    {
        $sessionUserKey = (string) config('bherila-auth.two_factor.session_user_key', 'bherila_auth_2fa_user_id');
        if (! $request->session()->has($sessionUserKey)) {
            return redirect()->route('login');
        }

        return view('auth.two-factor', ['attemptToken' => $token]);
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();
        $this->audit->loggedOut($request, $user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
