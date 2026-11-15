<?php

namespace App\Support;

use App\Models\User;
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
    }
}
