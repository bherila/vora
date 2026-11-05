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

            // SQLite index names are database-wide. The provider's users table
            // must not reuse names such as users_email_unique from Vora's table.
            // MariaDB indexes are table-scoped, and prefixing its longer names
            // would exceed its 64-character identifier limit.
            config(['database.connections.auth-manager.prefix_indexes' => config('database.connections.auth-manager.driver') === 'sqlite']);
        }
    }
}
