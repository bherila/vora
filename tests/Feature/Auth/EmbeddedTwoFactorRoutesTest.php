<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use BWH\Auth\Mail\TwoFactorLoginMail;
use BWH\Auth\Models\TwoFactorAttempt;
use BWH\AuthManager\Embedded\EmbeddedContext;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EmbeddedAuthTestCase;

class EmbeddedTwoFactorRoutesTest extends EmbeddedAuthTestCase
{
    /** @return array<string, array{bool}> */
    public static function routeModes(): array
    {
        return ['uncached' => [false], 'cached routes after a fresh boot' => [true]];
    }

    #[DataProvider('routeModes')]
    public function test_provider_email_urls_forms_and_guard_survive_caching_while_host_credentials_stay_disabled(bool $cached): void
    {
        if ($cached) {
            $this->cacheRoutesAndBootFreshApplication();
        }
        config(['auth-manager.sign_in.code_after_password' => true]);
        Mail::fake();
        [$provider] = $this->grantedClient();
        $host = User::factory()->approved()->create(['email' => $provider->email]);
        $this->freshRequest()->post('/auth/login', ['email' => $provider->email, 'password' => self::PASSWORD])->assertRedirect();
        $this->assertGuest('web');
        $this->assertGuest('auth-manager');
        $providerAttempt = app(EmbeddedContext::class)->run(fn () => TwoFactorAttempt::sole());

        $links = [];
        Mail::assertSent(TwoFactorLoginMail::class, function (TwoFactorLoginMail $mail) use (&$links): bool {
            $links[(string) $mail->attempt->token] = [$mail->confirmUrl, $mail->reportUrl];

            return true;
        });
        $base = '/auth/api/auth/two-factor';
        $this->assertSame(self::ORIGIN.$base.'/confirm/'.$providerAttempt->token, $links[$providerAttempt->token][0]);
        $this->assertSame(self::ORIGIN.$base.'/report/'.$providerAttempt->token, $links[$providerAttempt->token][1]);
        foreach (['confirm', 'report'] as $action) {
            $this->assertNull(Route::getRoutes()->getByName('bherila-auth.two-factor.'.$action));
            $this->assertNull(Route::getRoutes()->getByName('bherila-auth.two-factor.'.$action.'.submit'));
            $this->assertSame(ltrim($base, '/').'/'.$action.'/{token}', Route::getRoutes()->getByName('auth-manager.two-factor.'.$action)?->uri());
            $this->assertSame(ltrim($base, '/').'/'.$action.'/{token}', Route::getRoutes()->getByName('auth-manager.two-factor.'.$action.'.submit')?->uri());
            $this->freshRequest()->get($base.'/'.$action.'/'.$providerAttempt->token)->assertOk()
                ->assertSee('action="'.self::ORIGIN.$base.'/'.$action.'/'.$providerAttempt->token.'"', false);
            $this->assertGuest('web');
            $this->assertGuest('auth-manager');
        }

        $this->freshRequest()->getJson('/api/auth/two-factor/confirm/'.$providerAttempt->token)->assertNotFound();
        $this->freshRequest()->postJson('/auth/api/auth/two-factor/confirm/'.$providerAttempt->token)->assertUnprocessable();
        $this->assertGuest('web');
        $this->assertGuest('auth-manager');
        $this->freshRequest()->postJson('/auth/api/auth/two-factor/verify', ['attempt_token' => $providerAttempt->token, 'code' => $providerAttempt->code])->assertOk();
        $this->assertAuthenticatedAs($provider, 'auth-manager');
        $this->assertGuest('web');
        $this->assertDatabaseCount('auth_two_factor_attempts', 0);
        $this->assertNull($host->fresh()->last_login_at);
        $this->assertSame('bherila-auth.two-factor.', config('bherila-auth.two_factor.route_name_prefix'));
    }

    private function cacheRoutesAndBootFreshApplication(): void
    {
        $cache = $this->keysDirectory.'/routes.php';
        $key = 'APP_ROUTES_CACHE';
        $saved = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        $_ENV[$key] = $_SERVER[$key] = $cache;
        putenv($key.'='.$cache);
        try {
            $host = DB::connection();
            $pdo = $host->getPdo();
            $database = $host->getDatabaseName();
            $this->artisan('route:cache')->assertSuccessful();
            $this->assertFileExists($cache);
            $this->refreshApplication();
            $this->withoutVite();
            // Preserve the same isolated database across the new application.
            // Local SQLite stays in memory; CI keeps its own parallel database.
            foreach ([DB::connection(), DB::connection('auth-manager')] as $connection) {
                $connection->setDatabaseName($database);
                $connection->setPdo($pdo);
                $connection->setReadPdo($pdo);
            }
            $this->assertDatabaseIsSafe();
            $this->assertTrue($this->app->routesAreCached());
            $this->assertInstanceOf(CompiledRouteCollection::class, Route::getRoutes());
        } finally {
            [$env, $server, $process] = $saved;
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            putenv($process === false ? $key : $key.'='.$process);
        }
    }
}
