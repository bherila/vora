<?php

namespace App\Http\Middleware;

use BWH\Auth\Models\TwoFactorAttempt;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spends an emailed sign-in challenge after a few wrong codes. The package's
 * verify endpoint compares the code but never counts misses, so a six-digit
 * code could otherwise be guessed for as long as the challenge lives.
 *
 * A verify call that leaves its challenge unused did not sign in; counting those
 * per challenge needs no knowledge of the controller's responses. Once the limit
 * is reached the challenge is marked used, which the controller already refuses,
 * and the person signs in again for a fresh code.
 */
class LimitTwoFactorGuesses
{
    public const MAX_WRONG_CODES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->input('attempt_token');
        if (! is_string($token) || $token === '') {
            return $next($request);
        }

        $response = $next($request);

        $attempt = TwoFactorAttempt::query()->where('token', $token)->first();
        if ($attempt === null || ! $attempt->isValid()) {
            return $response;
        }

        $key = 'two-factor-misses:'.hash('sha256', $token);
        Cache::add($key, 0, $attempt->expires_at);
        $misses = (int) Cache::increment($key);

        if ($misses >= self::MAX_WRONG_CODES) {
            $attempt->forceFill(['is_used' => true])->save();
            Cache::forget($key);
        }

        return $response;
    }
}
