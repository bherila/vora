<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use BWH\Auth\OAuth\AuthenticationRequirement;
use BWH\AuthManager\Embedded\EmbeddedContext;
use BWH\AuthManager\Http\Middleware\RequireRecentPasskeyAuthentication;
use BWH\AuthManager\Models\User as ProviderUser;
use BWH\AuthManager\Services\AuthenticationContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Tests\Support\EmbeddedAuthTestCase;

class EmbeddedTestLoginTest extends EmbeddedAuthTestCase
{
    public function runDatabaseMigrations(): void
    {
        // Laravel otherwise creates Artisan for DatabaseMigrations before the
        // WithConsoleEvents trait can attach the production command dispatcher.
        $this->setUpWithConsoleEvents();
        parent::runDatabaseMigrations();
    }

    public function test_private_cli_provisioning_login_expiry_and_retirement_stay_in_the_provider(): void
    {
        $this->freezeTime();
        config(['auth-manager.sign_in.code_after_password' => true]);
        Mail::fake();
        $email = 'synthetic-login@example.test';
        $host = User::factory()->admin()->create(['email' => $email]);
        $hostBefore = (array) DB::table('users')->find($host->getKey());
        $connection = DB::getDefaultConnection();
        $this->assertFalse(Schema::hasColumn('users', 'is_test_login'));
        $client = app(EmbeddedContext::class)->run(fn () => app(ClientRepository::class)
            ->createAuthorizationCodeGrantClient('Example application', [self::ORIGIN.'/oauth/callback']));
        $path = realpath(sys_get_temp_dir()).'/embedded-test-login-'.Str::uuid().'.json';
        $deadline = now('UTC')->addHour();

        try {
            $this->artisan('auth-manager:test-logins', [
                'action' => 'create', '--name' => 'Synthetic login', '--email' => $email,
                '--expires-at' => $deadline->format('Y-m-d\TH:i:s\Z'),
                '--client' => [(string) $client->getKey()], '--output' => $path,
            ])->expectsOutputToContain('credentials written once to a private file')->assertSuccessful();

            $this->assertSame(0600, fileperms($path) & 0777);
            $credentials = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $subject = $credentials['subject'];
            $this->assertSame($email, $credentials['email']);
            $this->assertSame([(string) $client->getKey()], $credentials['oauth_client_ids']);
            $this->assertDatabaseHas('auth_manager_users', [
                'id' => $subject, 'email' => $email, 'is_test_login' => true, 'user_role' => 'user',
            ]);
            $this->assertDatabaseHas('auth_manager_oauth_client_grants', [
                'subject' => $subject, 'oauth_client_id' => $client->getKey(),
            ]);
            $this->assertSame($connection, DB::getDefaultConnection());

            $page = $this->get('/auth/login')->assertOk();
            $session = $page->getCookie('auth_manager_session');
            $this->assertNotNull($session);
            $login = $this->freshRequest()->withCookie('auth_manager_session', (string) $session->getValue())
                ->post('/auth/login', ['email' => $email, 'password' => $credentials['password']])
                ->assertRedirect(self::ORIGIN.'/auth');
            Mail::assertNothingSent();
            $this->assertAuthenticated('auth-manager');
            $this->assertGuest('web');
            $this->assertDatabaseCount('auth_manager_two_factor_attempts', 0);
            app(EmbeddedContext::class)->run(function (): void {
                $this->assertSame(['pwd'], session(AuthenticationContext::SESSION_KEY.'.amr'));
                $this->assertSame(AuthenticationRequirement::ACR_SINGLE, session(AuthenticationContext::SESSION_KEY.'.acr'));
                $this->assertFalse(session()->has(RequireRecentPasskeyAuthentication::SESSION_KEY));
            });

            $session = $login->getCookie('auth_manager_session');
            $this->assertNotNull($session);
            $this->travelTo($deadline);
            $this->assertNull(DB::table('auth_manager_users')->where('id', $subject)->value('disabled_at'));
            $this->freshRequest()->withCookie('auth_manager_session', (string) $session->getValue())
                ->getJson('/auth/settings')->assertUnauthorized();
            $this->assertGuest('auth-manager');
            $this->assertGuest('web');

            // The host scheduler launches this exact registered command. Run it
            // in-process so the guarded SQLite :memory: fixture remains shared.
            $scheduled = array_filter(app(Schedule::class)->events(), static fn ($event): bool => is_string($event->command) && str_contains($event->command, 'auth-manager:test-logins expire'));
            $this->assertCount(1, $scheduled);
            $this->artisan('auth-manager:test-logins', ['action' => 'expire'])
                ->expectsOutput('Disabled expired test logins: 1')->assertSuccessful();
            $this->assertNotNull(DB::table('auth_manager_users')->where('id', $subject)->value('disabled_at'));
            $this->artisan('auth-manager:test-logins', ['action' => 'retire', 'subject' => $subject])->assertSuccessful();
            $this->assertNotNull(DB::table('auth_manager_users')->where('id', $subject)->value('deleted_at'));
            $this->assertTrue(app(EmbeddedContext::class)->run(fn () => ProviderUser::withTrashed()->findOrFail($subject)->isTestLogin()));
            $this->assertSame($hostBefore, (array) DB::table('users')->find($host->getKey()));
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('auth_audit_log', 0);
            $this->assertSame($connection, DB::getDefaultConnection());
            $this->assertSame('web', auth()->getDefaultDriver());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
