<?php

namespace App\Support;

use App\Models\User;
use BWH\Auth\Models\TwoFactorAttempt;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Named rate limiters. Each keys its own bucket: an unprefixed `throttle:N,M`
 * shares one per-user (or per-IP) counter with every other unprefixed throttle,
 * so routes must use either a prefixed throttle or one of these.
 */
final class RateLimits
{
    public static function register(): void
    {
        // Counts only PATCH /api/account requests that actually change the
        // address: each sends mail to two addresses.
        RateLimiter::for('email-change', function (Request $request): Limit {
            $user = $request->user();
            $email = $request->input('email');

            if (! $user instanceof User || ! is_string($email) || $email === $user->email) {
                return Limit::none();
            }

            return Limit::perHour(6)->by('email-change:'.$user->getKey());
        });

        // Emailed sign-in codes. LimitTwoFactorGuesses spends a challenge after
        // five wrong codes, so guessing is bounded per challenge; these bound how
        // fast one challenge is tried and how fast one address tries challenges.
        // The per-IP ceiling stays above a shared NAT (or the browser suite) signing
        // many people in, since new challenges are already bounded by the login
        // throttle.
        RateLimiter::for('two-factor-verify', function (Request $request): array {
            $limits = [Limit::perMinute(60)->by('ip:'.$request->ip())];
            $token = $request->input('attempt_token');
            if (is_string($token) && $token !== '') {
                $limits[] = Limit::perMinute(10)->by('attempt:'.hash('sha256', $token));
            }

            return $limits;
        });

        // Each resend emails a new code, so bound it per account as well as per IP.
        RateLimiter::for('two-factor-resend', function (Request $request): array {
            $limits = [Limit::perMinute(6)->by('ip:'.$request->ip())];
            $token = $request->input('attempt_token');
            $userId = is_string($token) && $token !== ''
                ? TwoFactorAttempt::query()->where('token', $token)->value('user_id')
                : null;
            if ($userId !== null) {
                $limits[] = Limit::perMinutes(15, 3)->by('user:'.$userId);
            }

            return $limits;
        });

        // Each request can email a reset link: per IP, and per address so one
        // mailbox cannot be flooded from many addresses.
        RateLimiter::for('forgot-password', fn (Request $request): array => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perHour(5)->by('email:'.self::emailKey($request)),
        ]);

        // Each submission emails the address it names.
        RateLimiter::for('waitlist', fn (Request $request): array => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perHour(3)->by('email:'.self::emailKey($request)),
        ]);

        RateLimiter::for('register', fn (Request $request): array => [
            Limit::perMinutes(10, 10)->by('ip:'.$request->ip()),
            Limit::perHour(5)->by('email:'.self::emailKey($request)),
        ]);
    }

    /** A per-address bucket key that does not keep the address itself in the cache. */
    public static function emailKey(Request $request): string
    {
        $email = $request->input('email');

        return hash('sha256', is_string($email) ? mb_strtolower(trim($email)) : '');
    }
}
