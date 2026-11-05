<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\LocalIdentityMigration;
use BWH\Auth\Models\TwoFactorAttempt;
use BWH\AuthManager\Embedded\EmbeddedContext;
use BWH\AuthManager\Models\User as ProviderUser;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\ClientRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Support\EmbeddedAuthTestCase;

class LocalIdentityStateJourneyTest extends EmbeddedAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        [$id, $secret] = app(EmbeddedContext::class)->run(function (): array {
            $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Vora', [self::ORIGIN.'/oauth/callback']);

            return [(string) $client->getKey(), (string) $client->plain_secret];
        });
        config([
            'bherila-auth.oauth_client.client_id' => $id,
            'bherila-auth.oauth_client.client_secret' => $secret,
            'auth-manager.sign_in.code_after_password' => true,
            'auth-manager.sign_in.code_only' => false,
            // Fresh browser requests must persist host OAuth state across session stores.
            'session.driver' => 'database',
        ]);
        Mail::fake();
        Notification::fake();
        $this->withCredentials();
    }

    /** @return array<string, array{string, string}> */
    public static function hostAuthorizationStates(): array
    {
        return [
            'banned' => ['banned', '/account/banned'],
            'self-deactivated' => ['deactivated', '/account/deactivated'],
            'pending approval' => ['unapproved', '/pending-approval'],
            'unverified email' => ['unverified', '/email/verify'],
        ];
    }

    #[DataProvider('hostAuthorizationStates')]
    public function test_migrated_people_can_authenticate_and_reach_their_existing_host_gate(string $state, string $gate): void
    {
        $attributes = match ($state) {
            'banned' => ['banned_at' => now()],
            'deactivated' => ['deactivated_at' => now()],
            'unapproved' => ['approved_at' => null],
            default => ['email_verified_at' => null],
        };
        $source = User::factory()->approved()->create(['id' => 42, 'password' => self::PASSWORD, ...$attributes]);
        $report = app(LocalIdentityMigration::class)->run(true);
        $this->assertSame('migrated', $report['accounts'][0]['status']);
        $this->assertDatabaseHas('auth_manager_users', ['id' => 42, 'disabled_at' => null, 'user_role' => 'user']);
        $this->assertDatabaseHas('auth_manager_oauth_client_grants', ['subject' => 42]);
        $this->assertFalse($source->fresh()->isAdmin());

        $this->signInToProvider($source);
        $cookie = $this->signInToHost($source);
        if (in_array($state, ['banned', 'deactivated'], true)) {
            $this->freshRequest()->withCookie($cookie->getName(), (string) $cookie->getValue())
                ->get('/feed')->assertRedirect(self::ORIGIN.$gate);
        }
        $this->freshRequest()->withCookie($cookie->getName(), (string) $cookie->getValue())->get($gate)->assertOk();
        $this->freshRequest()->withCookie($cookie->getName(), (string) $cookie->getValue())->getJson('/feed')->assertForbidden();

        if ($state === 'banned') {
            $this->assertNotNull($source->fresh()->banned_at);
            $this->freshRequest()->withCookie($cookie->getName(), (string) $cookie->getValue())
                ->postJson('/api/account/appeal', ['message' => 'Please review this account ban.'])->assertOk();
            $this->assertSame('Please review this account ban.', $source->fresh()->ban_appeal_message);
        } elseif ($state === 'deactivated') {
            $this->assertNotNull($source->fresh()->deactivated_at);
            $this->freshRequest()->withCookie($cookie->getName(), (string) $cookie->getValue())
                ->post('/account/reactivate')->assertRedirect('/');
            $this->assertNull($source->fresh()->deactivated_at);
        } elseif ($state === 'unapproved') {
            $this->assertNull($source->fresh()->approved_at);
        } else {
            $this->assertNull($source->fresh()->email_verified_at);
            $this->freshRequest()->withCookie($cookie->getName(), (string) $cookie->getValue())
                ->from(self::ORIGIN.'/email/verify')->post('/email/verification-notification')->assertRedirect();
            Notification::assertSentTo($source, VerifyEmail::class);
        }
    }

    /** @return array<string, array{bool}> */
    public static function deniedStates(): array
    {
        return ['disabled' => [false], 'deleted' => [true]];
    }

    #[DataProvider('deniedStates')]
    public function test_migrated_disabled_and_deleted_people_cannot_authenticate_even_with_the_correct_password(bool $deleted): void
    {
        $source = User::factory()->approved()->create([
            'password' => self::PASSWORD,
            ...($deleted ? ['deleted_at' => now()] : ['is_disabled' => true]),
        ]);
        $this->assertSame('migrated_disabled', app(LocalIdentityMigration::class)->run(true)['accounts'][0]['status']);
        $page = $this->freshRequest()->get('/auth/login')->assertOk();
        $cookie = $page->getCookie('auth_manager_session');
        $this->assertNotNull($cookie);
        $this->freshRequest()->withCookie($cookie->getName(), (string) $cookie->getValue())
            ->from(self::ORIGIN.'/auth/login')
            ->post('/auth/login', ['email' => $source->email, 'password' => self::PASSWORD])
            ->assertRedirect(self::ORIGIN.'/auth/login');
        $this->assertGuest('auth-manager');
        $this->assertGuest('web');
        $this->assertDatabaseCount('auth_manager_two_factor_attempts', 0);
        $this->assertDatabaseCount('auth_manager_oauth_client_grants', 0);
        Mail::assertNothingSent();
    }

    /** Real password and email-code verification against the copied provider credential. */
    private function signInToProvider(User $source): void
    {
        $page = $this->freshRequest()->get('/auth/login')->assertOk();
        $cookie = $page->getCookie('auth_manager_session');
        $this->assertNotNull($cookie);
        $challenge = $this->freshRequest()->withCookie($cookie->getName(), (string) $cookie->getValue())
            ->post('/auth/login', ['email' => $source->email, 'password' => self::PASSWORD])
            ->assertRedirect(self::ORIGIN.'/auth/login');
        $this->assertGuest('auth-manager');
        $attempt = app(EmbeddedContext::class)->run(fn () => TwoFactorAttempt::sole());
        $this->assertSame($source->id, $attempt->user_id);
        $cookie = $challenge->getCookie('auth_manager_session');
        $this->assertNotNull($cookie);
        $this->freshRequest()->withCookie($cookie->getName(), (string) $cookie->getValue())
            ->postJson('/auth/api/auth/two-factor/verify', ['attempt_token' => $attempt->token, 'code' => $attempt->code])
            ->assertOk()->assertJsonPath('success', true);
        $provider = app(EmbeddedContext::class)->run(fn () => ProviderUser::findOrFail($source->id));
        $this->assertAuthenticatedAs($provider, 'auth-manager');
        $this->assertGuest('web');
    }

    /** The network protocol is covered separately; exercise Vora's real OAuth callback/gates. */
    private function signInToHost(User $source): Cookie
    {
        Http::fake([
            self::ORIGIN.'/auth/oauth/token' => Http::response(['access_token' => 'verified-provider-token', 'token_type' => 'Bearer']),
            self::ORIGIN.'/auth/api/oauth/user' => Http::response([
                'sub' => (string) $source->id, 'name' => $source->name, 'email' => $source->email, 'credential_version' => 0,
            ]),
        ]);
        $start = $this->freshRequest()->get(self::ORIGIN.'/login')->assertRedirect();
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $flow);
        $this->assertArrayHasKey('state', $flow);
        $cookie = $start->getCookie((string) config('session.cookie'));
        $this->assertNotNull($cookie);
        $callback = $this->freshRequest()->withCookie($cookie->getName(), (string) $cookie->getValue())
            ->get(self::ORIGIN.'/oauth/callback?'.http_build_query(['code' => 'provider-code', 'state' => $flow['state']]))->assertRedirect();
        $this->assertAuthenticatedAs($source, 'web');
        $this->assertSame(1, User::count());
        $cookie = $callback->getCookie((string) config('session.cookie'));
        $this->assertNotNull($cookie);

        return $cookie;
    }
}
