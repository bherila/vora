<?php

use App\Http\Middleware\EnsureAgeEligible;
use App\Http\Middleware\EnsureApproved;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureNotBanned;
use App\Http\Middleware\EnsureNotDeactivated;
use App\Http\Middleware\EnsureSignInMode;
use App\Http\Middleware\RequireProviderAccount;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

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
            'module' => EnsureModuleEnabled::class,
            'sign-in' => EnsureSignInMode::class,
        ]);
        // Decide account eligibility before resolving content model bindings;
        // an ineligible viewer must not probe existing versus missing objects.
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureAgeEligible::class);

        // Gate self-deactivated accounts to the reactivate page across the whole
        // web surface — including the package auth endpoints (password/passkeys)
        // and the public home page a fresh login would otherwise land them on.
        $middleware->web(append: [
            // First: a session the identity provider no longer vouches for ends
            // before any account gate or page sees it.
            RequireProviderAccount::class,
            EnsureNotBanned::class,
            EnsureNotDeactivated::class,
            EnsureAgeEligible::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
