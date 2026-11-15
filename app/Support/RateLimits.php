<?php

namespace App\Support;

use App\Models\User;
use App\Services\Account\EmailChangeService;
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
    /** Writes one account (or, for a guest, one IP) may make across the web app per minute. */
    public const WEB_WRITES_PER_MINUTE = 300;

    /** Checks of one account's current password, across every endpoint that makes one, per minute. */
    public const PASSWORD_CHECKS_PER_MINUTE = 6;

    public static function register(): void
    {
        // The backstop on every web route (bootstrap/app.php): a write with no
        // limiter of its own still cannot be repeated without bound. Its bucket
        // is separate from every route's own limiter, and it is generous so no
        // ordinary session reaches it; uploads retry a 429. Reads are not counted.
        RateLimiter::for('web-writes', function (Request $request): Limit {
            if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
                return Limit::none();
            }

            $user = $request->user();

            return Limit::perMinute(self::WEB_WRITES_PER_MINUTE)->by($user !== null
                ? 'user:'.$user->getAuthIdentifier()
                : 'ip:'.$request->ip());
        });

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

        // Every request that checks the signed-in account's password shares one
        // bucket per account: confirming the password, and a PATCH /api/account
        // that changes the address (no other profile save checks it, so none
        // counts). A stolen session cannot guess faster by switching endpoints.
        RateLimiter::for('password-check', function (Request $request): Limit {
            $user = $request->user();
            if ($request->routeIs('account.update') && ! EmailChangeService::changes($user, $request->input('email'))) {
                return Limit::none();
            }

            return Limit::perMinute(self::PASSWORD_CHECKS_PER_MINUTE)->by($user !== null
                ? 'user:'.$user->getAuthIdentifier()
                : 'ip:'.$request->ip());
        });

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
