<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\RequireProviderAccount;
use App\Models\MailDelivery;
use App\Models\User;
use App\Services\Auth\SignIn;
use App\Services\Auth\VoraAuthUserPolicy;
use BWH\Auth\Contracts\AuthUserPolicy;
use BWH\Auth\Http\Controllers\TwoFactorController;
use BWH\Auth\Mail\TwoFactorLoginMail;
use BWH\Auth\Models\TwoFactorAttempt;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationClient;
use BWH\Auth\OAuth\Invitations\ApplicationInvitations;
use BWH\AuthManager\Embedded\EmbeddedContext;
use BWH\AuthManager\Models\User as ProviderUser;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\EmbeddedAuthTestCase;

class EmbeddedAuthTest extends EmbeddedAuthTestCase
{
    public function test_the_shared_two_factor_controller_finishes_provider_password_and_code_login_only_in_its_guard(): void
    {
        config(['auth-manager.sign_in.code_after_password' => true]);
        Mail::fake();
        [$provider] = $this->grantedClient();
        $host = User::factory()->approved()->create(['email' => $provider->email]);
        $this->assertInstanceOf(VoraAuthUserPolicy::class, app(AuthUserPolicy::class));
        // Laravel can resolve a credential controller before EnterEmbeddedContext
        // runs. Keep that exact instance to exercise its host-resolved policy.
        $this->app->instance(TwoFactorController::class, app(TwoFactorController::class));
        $page = $this->get('/auth/login')->assertOk();
        $session = $page->getCookie('auth_manager_session');
        $this->assertNotNull($session);

        $challenge = $this->freshRequest()->withCookie('auth_manager_session', (string) $session->getValue())
            ->post('/auth/login', ['email' => $provider->email, 'password' => self::PASSWORD])
            ->assertRedirect(self::ORIGIN.'/auth/login');

        $this->assertGuest('auth-manager');
        $this->assertGuest('web');
        $attempt = app(EmbeddedContext::class)->run(fn () => TwoFactorAttempt::sole());
        $this->assertSame($provider->getKey(), $attempt->user_id);
        Mail::assertSent(TwoFactorLoginMail::class, fn (TwoFactorLoginMail $mail): bool => $mail->hasTo($provider->email)
            && str_starts_with($mail->confirmUrl, self::ORIGIN.'/auth/api/auth/two-factor/'));
        $session = $challenge->getCookie('auth_manager_session');
        $this->assertNotNull($session);
        $this->freshRequest()->withCookie('auth_manager_session', (string) $session->getValue())->get('/auth/login')
            ->assertOk()->assertSee('data-attempt-token="'.$attempt->token.'"', false);

        $this->freshRequest()->withCookie('auth_manager_session', (string) $session->getValue())
            ->postJson('/auth/api/auth/two-factor/verify', [
                'attempt_token' => $attempt->token,
                'code' => $attempt->code === '000000' ? '000001' : '000000',
            ])->assertStatus(422);
        $this->assertGuest('auth-manager');
        $this->freshRequest()->withCookie('auth_manager_session', (string) $session->getValue())
            ->postJson('/auth/api/auth/two-factor/verify', ['attempt_token' => $attempt->token, 'code' => $attempt->code])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('redirect', '/auth');

        $this->assertAuthenticatedAs($provider, 'auth-manager');
        $this->assertGuest('web');
        $this->assertTrue(app(EmbeddedContext::class)->run(fn () => $attempt->fresh()->is_used));
        $this->assertNull($host->fresh()->last_login_at);
    }

    public function test_the_host_policy_accepts_provider_credentials_only_inside_the_active_embedded_context(): void
    {
        [$provider] = $this->grantedClient();
        $policy = app(AuthUserPolicy::class);
        $request = Request::create(self::ORIGIN.'/auth/login');

        $this->assertFalse($policy->canLogin($provider, $request));
        $this->assertFalse($policy->canPasskeyLogin($provider, $request));
        app(EmbeddedContext::class)->run(function () use ($policy, $provider, $request): void {
            $this->assertTrue($policy->canLogin($provider, $request));
            $this->assertTrue($policy->canPasskeyLogin($provider, $request));
            $provider->forceFill(['disabled_at' => now()]);
            $this->assertFalse($policy->canLogin($provider, $request));
            $this->assertFalse($policy->canPasskeyLogin($provider, $request));
            $provider->forceFill(['disabled_at' => null, 'deleted_at' => now()]);
            $this->assertFalse($policy->canLogin($provider, $request));
            $this->assertFalse($policy->canPasskeyLogin($provider, $request));
        });
        $this->assertFalse($policy->canLogin($provider, $request));
        $this->assertFalse($policy->canPasskeyLogin($provider, $request));
    }

    public function test_invitations_use_the_real_client_and_sign_the_mounted_endpoint_audience(): void
    {
        config([
            'bherila-auth.oauth_client.provider' => 'embedded-test',
            'bherila-auth.oauth_client.client_id' => 'vora-client',
            'bherila-auth.oauth_client.client_secret' => 'client-secret',
            'bherila-auth.application_invitations.key_id' => 'invitation-test-key',
            'bherila-auth.application_invitations.private_key_path' => $this->keysDirectory.'/oauth-private.key',
        ]);
        $endpoint = self::ORIGIN.'/auth/api/application-invitations';
        Http::preventStrayRequests();
        Http::fake([
            self::ORIGIN.'/auth/oauth/token' => Http::response([
                'access_token' => 'invitation-token', 'token_type' => 'Bearer',
                'expires_in' => 300, 'scope' => 'invitations:admit',
            ]),
            $endpoint => Http::response(['invitation' => [
                'id' => 12, 'status' => 'pending', 'expires_at' => now()->addDay()->format('Y-m-d\TH:i:s\Z'),
                'link' => self::ORIGIN.'/auth/invitations/accept/test-link', 'email_sent' => true,
            ]], 201),
        ]);
        $this->assertInstanceOf(ApplicationInvitationClient::class, app(ApplicationInvitations::class));
        $admin = User::factory()->admin()->create(['oauth_provider' => 'embedded-test', 'oauth_subject' => '42']);

        $this->actingAs($admin)->withSession([RequireProviderAccount::SESSION_KEY => ['embedded-test', '42']])
            ->postJson('/api/invitations', ['email' => 'guest@example.test'])->assertCreated();

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === self::ORIGIN.'/auth/oauth/token'
            && $request['grant_type'] === 'client_credentials' && $request['scope'] === 'invitations:admit');
        Http::assertSent(function (ClientRequest $request) use ($endpoint): bool {
            if ($request->url() !== $endpoint) {
                return false;
            }
            $this->assertSame('Bearer invitation-token', $request->header('Authorization')[0]);
            [$header, $payload, $signature] = explode('.', $request->header('Actor-Assertion')[0]);
            $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($endpoint, $claims['aud']);
            $this->assertSame('42', $claims['sub']);
            $this->assertSame('vora-client', $claims['iss']);
            $this->assertSame(hash('sha256', $request->body()), $claims['body_sha256']);
            $this->assertSame(1, openssl_verify($header.'.'.$payload, base64_decode(strtr($signature, '-_', '+/')),
                file_get_contents($this->keysDirectory.'/oauth-public.key'), OPENSSL_ALGO_SHA256));

            return true;
        });
        Http::assertSentCount(2);
        $this->assertDatabaseHas('provider_invitations', ['provider_invitation_id' => 12, 'inviter_user_id' => $admin->id]);
    }

    public function test_provider_mail_uses_the_shared_transport_without_entering_the_host_delivery_log(): void
    {
        Exceptions::fake();
        [$provider] = $this->grantedClient();
        $host = User::factory()->approved()->create(['email' => $provider->email]);

        app(EmbeddedContext::class)->run(fn () => Mail::raw('Provider message', fn ($mail) => $mail->to($provider->email)->subject('Provider message')));
        $this->assertSame(0, MailDelivery::count());
        Exceptions::assertNothingReported();

        Mail::raw('Host message', fn ($mail) => $mail->to($host->email)->subject('Host message'));
        $this->assertSame($host->id, MailDelivery::sole()->user_id);
        Exceptions::assertNothingReported();
    }

    public function test_the_mounted_provider_leaves_host_routes_and_credential_pages_separate(): void
    {
        $connection = DB::getDefaultConnection();
        config(['bherila-auth.oauth_client.client_id' => 'vora-client', 'bherila-auth.oauth_client.client_secret' => 'secret']);

        $this->assertSame('embedded', SignIn::mode());
        $this->assertTrue(SignIn::usesProvider());
        $this->assertSame(self::ORIGIN.'/auth', config('bherila-auth.oauth_client.base_url'));
        $this->assertSame('/login', route('login', absolute: false));
        $this->assertSame('/auth/login', route('auth-manager.login', absolute: false));
        $this->assertStringStartsWith(self::ORIGIN.'/auth/oauth/authorize?', (string) $this->get('/login')->assertRedirect()->headers->get('Location'));
        $this->get('/auth/login')->assertOk()->assertSee('action="'.self::ORIGIN.'/auth/login"', false);
        $this->get('/register')->assertNotFound();
        $this->post('/login', ['email' => 'person@example.test', 'password' => self::PASSWORD])->assertNotFound();
        $this->post('/oauth/token')->assertNotFound();
        $this->get('/privacy')->assertOk();
        $this->assertSame('web', auth()->getDefaultDriver());
        $this->assertSame($connection, DB::getDefaultConnection());
    }

    public function test_provider_sessions_and_auth_events_do_not_authenticate_or_update_host_accounts(): void
    {
        [$provider] = $this->grantedClient();
        $host = User::factory()->approved()->create(['email' => $provider->email]);
        $page = $this->get('/auth/login')->assertOk();
        $session = $page->getCookie('auth_manager_session');
        $this->assertNotNull($session);
        $this->assertSame('/auth', $session->getPath());
        $this->assertNull($session->getDomain());
        $this->assertNull($page->getCookie((string) config('session.cookie')));
        $this->assertNull($page->getCookie('XSRF-TOKEN'));

        $this->freshRequest()->withCookie('auth_manager_session', (string) $session->getValue())
            ->post('/auth/login', ['email' => $provider->email, 'password' => self::PASSWORD])
            ->assertRedirect(self::ORIGIN.'/auth');

        $this->assertGuest('web');
        $this->assertNull($host->fresh()->last_login_at);
        $this->assertSame(1, User::count());
        $this->assertTrue(Schema::hasTable('auth_manager_users'));
        $this->assertTrue(Schema::hasTable('auth_manager_oauth_clients'));
        $this->assertFalse(Schema::hasTable('oauth_clients'));
        $this->assertSame([$provider->email], DB::table('auth_manager_users')->pluck('email')->all());
    }

    public function test_provider_password_login_uses_its_own_csrf_token(): void
    {
        [$provider] = $this->grantedClient();
        $page = $this->get('/auth/login')->assertOk();
        $session = $page->getCookie('auth_manager_session');
        $this->assertNotNull($session);
        preg_match('~<meta name="csrf-token" content="([^"]+)">~', (string) $page->getContent(), $match);
        $this->assertNotEmpty($match[1] ?? null);

        $this->app['env'] = 'production';
        try {
            $this->freshRequest()->withCookie('auth_manager_session', (string) $session->getValue())
                ->post('/auth/login', ['email' => $provider->email, 'password' => self::PASSWORD])->assertStatus(419);
            $this->freshRequest()->withCookie('auth_manager_session', (string) $session->getValue())
                ->post('/auth/login', ['email' => $provider->email, 'password' => self::PASSWORD, '_token' => $match[1]])
                ->assertRedirect(self::ORIGIN.'/auth');
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_authorization_code_pkce_and_identity_use_real_provider_endpoints(): void
    {
        [$provider, $client, $secret] = $this->grantedClient();
        $verifier = Str::random(64);
        $query = [
            'client_id' => (string) $client->getKey(),
            'redirect_uri' => self::ORIGIN.'/oauth/callback',
            'response_type' => 'code', 'scope' => 'identity:read', 'state' => 'host-state',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];
        ksort($query);
        $authorize = '/auth/oauth/authorize?'.http_build_query($query);
        $guest = $this->get($authorize)->assertRedirect(self::ORIGIN.'/auth/login');
        $session = $guest->getCookie('auth_manager_session');
        $this->assertNotNull($session);
        $signedIn = $this->freshRequest()->withCookie('auth_manager_session', (string) $session->getValue())
            ->post('/auth/login', ['email' => $provider->email, 'password' => self::PASSWORD])->assertRedirect(self::ORIGIN.$authorize);
        $session = $signedIn->getCookie('auth_manager_session');
        $this->assertNotNull($session);
        $approved = $this->freshRequest()->withCookie('auth_manager_session', (string) $session->getValue())->get($authorize)->assertRedirect();
        parse_str((string) parse_url((string) $approved->headers->get('Location'), PHP_URL_QUERY), $response);
        $this->assertSame('host-state', $response['state'] ?? null);
        $this->assertNotEmpty($response['code'] ?? null);
        $parameters = [
            'grant_type' => 'authorization_code', 'client_id' => (string) $client->getKey(),
            'client_secret' => $secret, 'redirect_uri' => self::ORIGIN.'/oauth/callback',
            'code' => $response['code'], 'code_verifier' => $verifier,
        ];
        $token = $this->freshRequest()->post('/auth/oauth/token', $parameters)->assertOk()->json('access_token');
        $this->assertIsString($token);
        $this->freshRequest()->withToken($token)->getJson('/auth/api/oauth/user')->assertOk()
            ->assertJsonPath('sub', (string) $provider->getKey())->assertJsonPath('email', $provider->email);
        $this->freshRequest()->post('/auth/oauth/token', $parameters)->assertStatus(400);
        $this->assertGuest('web');
        $this->assertSame(0, User::count());
    }

    public function test_disabled_and_deleted_provider_accounts_cannot_start_a_session(): void
    {
        [$provider] = $this->grantedClient();
        app(EmbeddedContext::class)->run(fn () => ProviderUser::whereKey($provider->getKey())->update(['disabled_at' => now()]));
        $page = $this->get('/auth/login');
        $session = $page->getCookie('auth_manager_session');
        $this->assertNotNull($session);
        $refused = $this->freshRequest()->from(self::ORIGIN.'/auth/login')->withCookie('auth_manager_session', (string) $session->getValue())
            ->post('/auth/login', ['email' => $provider->email, 'password' => self::PASSWORD])->assertRedirect(self::ORIGIN.'/auth/login');
        $this->assertGuest('auth-manager');
        $session = $refused->getCookie('auth_manager_session');
        $this->assertNotNull($session);
        $this->freshRequest()->withCookie('auth_manager_session', (string) $session->getValue())
            ->get('/auth/login')->assertSee('Your account is disabled.');

        app(EmbeddedContext::class)->run(fn () => ProviderUser::whereKey($provider->getKey())->delete());
        $page = $this->freshRequest()->get('/auth/login');
        $session = $page->getCookie('auth_manager_session');
        $this->assertNotNull($session);
        $refused = $this->freshRequest()->from(self::ORIGIN.'/auth/login')->withCookie('auth_manager_session', (string) $session->getValue())
            ->post('/auth/login', ['email' => $provider->email, 'password' => self::PASSWORD])->assertRedirect(self::ORIGIN.'/auth/login');
        $this->assertGuest('auth-manager');
        $this->assertSame(0, User::count());
    }
}
