<?php

namespace App\Providers;

use App\Listeners\UpdateLastLoginDate;
use App\Models\ClientManagement\ClientCompany;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Csp\AddCspHeaders;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register login event listener
        Event::listen(Login::class, UpdateLastLoginDate::class);

        // Admin gate - check if user has admin role
        Gate::define('admin', function ($user) {
            return $user->hasRole('admin');
        });

        // Alias for backward compatibility (uppercase)
        Gate::define('Admin', function ($user) {
            return $user->hasRole('admin');
        });

        // Gate for accessing client company resources
        // User must be a member of the client company OR be an admin
        Gate::define('ClientCompanyMember', function ($user, $clientCompanyId) {
            // Admin users have access
            if ($user->hasRole('admin')) {
                return true;
            }

            // Check if user is a member of the client company
            $company = ClientCompany::find($clientCompanyId);
            if (! $company) {
                return false;
            }

            return $company->users()->where('user_id', $user->id)->exists();
        });

        // Register the Spatie CSP middleware globally if the HTTP kernel is available.
        if ($this->app->bound(Kernel::class)) {
            $this->app->make(Kernel::class)
                ->pushMiddleware(AddCspHeaders::class);
        }
    }
}
