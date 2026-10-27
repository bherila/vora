<?php

namespace App\Http\Middleware;

use App\Services\Auth\SignIn;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a route only under the named sign-in mode, so the local credential
 * pages vanish under provider sign-in and the provider callback under local.
 */
class EnsureSignInMode
{
    public function handle(Request $request, Closure $next, string $mode): Response
    {
        abort_unless(SignIn::mode() === $mode, 404);

        return $next($request);
    }
}
