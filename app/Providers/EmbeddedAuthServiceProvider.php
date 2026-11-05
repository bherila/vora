<?php

namespace App\Providers;

use App\Services\Auth\SignIn;
use BWH\AuthManager\Providers\EmbeddedServiceProvider;
use Illuminate\Support\ServiceProvider;

/** The installed provider stays inert until an operator selects embedded sign-in. */
class EmbeddedAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (config('auth.sign_in') === SignIn::EMBEDDED) {
            $this->app->register(EmbeddedServiceProvider::class);
        }
    }
}
