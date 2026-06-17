<?php

namespace App\Providers;

use App\Listeners\UpdateLastLoginDate;
use App\Models\Character;
use App\Models\Media;
use App\Models\StaticPage;
use App\Models\Story;
use App\Models\User;
use App\Policies\MediaPolicy;
use App\Policies\StoryPolicy;
use App\Services\Auth\VoraAuthUserPolicy;
use BWH\Auth\Contracts\AuthUserPolicy;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Spatie\Csp\AddCspHeaders;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // App-specific auth policy consumed by the bherila/auth-laravel package.
        $this->app->bind(AuthUserPolicy::class, VoraAuthUserPolicy::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keep last_login_at fresh on each login.
        Event::listen(Login::class, UpdateLastLoginDate::class);

        // Admin gate — admin flag plus the full login access model (approved and not
        // disabled). Used by admin routes and the package's audit-log endpoints
        // (see config/bherila-auth.php audit.admin_ability), including JSON endpoints
        // that do not otherwise pass the `approved` middleware, so a pending or
        // disabled admin must not slip through here.
        Gate::define('admin-only', fn (User $user): bool => $user->isAdmin() && $user->isApproved() && $user->canLogin());

        Gate::policy(Media::class, MediaPolicy::class);
        Gate::policy(Story::class, StoryPolicy::class);

        // Stable aliases for polymorphic story "involves" tags, so the database
        // stores short type keys instead of fully-qualified class names.
        Relation::morphMap([
            'user' => User::class,
            'character' => Character::class,
        ]);

        View::composer('layouts.app', function ($view): void {
            $footerPages = collect([
                ['label' => 'Privacy', 'url' => route('privacy')],
                ['label' => 'Terms', 'url' => route('terms')],
            ]);

            if (Schema::hasTable('static_pages')) {
                $databaseFooterPages = StaticPage::query()
                    ->where('is_published', true)
                    ->where('show_in_footer', true)
                    ->orderBy('sort_order')
                    ->orderBy('title')
                    ->get()
                    ->map(fn (StaticPage $page): array => [
                        'label' => $page->footer_label ?: $page->title,
                        'url' => in_array($page->slug, ['privacy', 'terms'], true) ? route($page->slug) : route('pages.show', $page->slug),
                    ]);

                if ($databaseFooterPages->isNotEmpty()) {
                    $footerPages = $databaseFooterPages;
                }
            }

            $view->with('footerPages', $footerPages);
        });

        // Register the Spatie CSP middleware globally if the HTTP kernel is available.
        if ($this->app->bound(Kernel::class)) {
            $this->app->make(Kernel::class)
                ->pushMiddleware(AddCspHeaders::class);
        }
    }
}
