<?php

use App\Http\Middleware\EnsureApproved;
use App\Http\Middleware\EnsureNotDeactivated;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'approved' => EnsureApproved::class,
        ]);

        // Gate self-deactivated accounts to the reactivate page across the whole
        // web surface — including the package auth endpoints (password/passkeys)
        // and the public home page a fresh login would otherwise land them on.
        $middleware->web(append: [
            EnsureNotDeactivated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
