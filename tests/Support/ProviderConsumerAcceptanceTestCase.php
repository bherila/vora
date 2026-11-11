<?php

namespace Tests\Support;

use App\Models\User;
use BWH\Auth\Mail\PasswordResetConfirmationMail;
use BWH\AuthManager\Embedded\EmbeddedContext;
use BWH\AuthManager\Http\Controllers\IdentityReconciliationController;
use BWH\AuthManager\Http\Controllers\IdentityStatusController;
use BWH\AuthManager\Http\Controllers\OAuthUserController;
use BWH\AuthManager\Http\Middleware\AuthenticateReconciliationClient;
use BWH\AuthManager\Http\Middleware\EnterEmbeddedContext;
use BWH\AuthManager\Models\User as ProviderUser;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use LogicException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/** Real provider handlers over an in-process transport, with real Vora HTTP requests. */
abstract class ProviderConsumerAcceptanceTestCase extends EmbeddedAuthTestCase
{
    protected ProviderUser $provider;

    protected User $consumer;

    protected string $providerNamespace = 'acceptance-provider';

    protected bool $providerUnavailable = false;

    protected int $statusCalls = 0;

    /** @var list<array{subject:string, transaction_level:int, deleted_at:mixed}> */
    protected array $acknowledgements = [];

    protected Cookie $consumerCookie;

    protected Cookie $providerCookie;

    protected string $hostDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->withoutVite();
        Cache::flush();
        $this->hostDatabase = DB::getDefaultConnection();
        [$this->provider, $client, $secret] = $this->grantedClient();
        config([
            'bherila-auth.oauth_client.provider' => $this->providerNamespace,
            'bherila-auth.oauth_client.client_id' => (string) $client->getKey(),
            'bherila-auth.oauth_client.client_secret' => $secret,
            'bherila-auth.oauth_client.redirect_uri' => self::ORIGIN.'/oauth/callback',
            'bherila-auth.provider_identity.enabled' => true,
            'session.driver' => 'database',
            'session.connection' => $this->hostDatabase,
            'session.table' => 'sessions',
        ]);
        $this->consumer = User::factory()->admin()->create([
            'email' => $this->provider->email, 'oauth_provider' => $this->providerNamespace,
            'oauth_subject' => (string) $this->provider->getKey(),
        ]);
        Http::preventStrayRequests();
        Http::fake(fn (ClientRequest $request) => $this->providerTransport($request));
    }

    protected function freshRequest(): static
    {
        parent::freshRequest();
        $this->app->forgetInstance('session.store');

        return $this;
    }

    private function providerTransport(ClientRequest $outgoing): mixed
    {
        if (! str_starts_with($outgoing->url(), self::ORIGIN.'/auth/')) {
            throw new LogicException('Acceptance transport refuses non-provider destinations.');
        }
        $path = (string) parse_url($outgoing->url(), PHP_URL_PATH);
        if ($path === '/auth/api/reconciliation/identity-status') {
            $this->statusCalls++;
            if ($this->providerUnavailable) {
                throw new ConnectionException('Synthetic provider outage');
            }
        }
        if (preg_match('~/identity-tombstones/([^/]+)/acknowledgement$~', $path, $match)) {
            $subject = app(EmbeddedContext::class)->run(fn () => (string) DB::table('identity_tombstones')->where('public_id', $match[1])->value('subject'));
            $this->acknowledgements[] = [
                'subject' => $subject,
                'transaction_level' => DB::connection($this->hostDatabase)->transactionLevel(),
                'deleted_at' => DB::connection($this->hostDatabase)->table('users')->where('oauth_provider', $this->providerNamespace)->where('oauth_subject', $subject)->value('deleted_at'),
            ];
        }
        $server = [];
        foreach ($outgoing->headers() as $name => $values) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = implode(', ', $values);
        }
        $request = Request::create($outgoing->url(), $outgoing->method(), $outgoing->data(), [], [], $server, $outgoing->body());
        $route = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn (?string $guard = null) => Auth::guard($guard)->user());
        $middleware = app('router')->gatherRouteMiddleware($route);
        $this->assertContains(EnterEmbeddedContext::class, $middleware);
        $action = match (true) {
            $path === '/auth/oauth/token' => AccessTokenController::class.'@issueToken',
            $path === '/auth/api/oauth/user' => OAuthUserController::class,
            $path === '/auth/api/reconciliation/identity-status' => IdentityStatusController::class,
            $path === '/auth/api/reconciliation/identity-tombstones' => IdentityReconciliationController::class.'@index',
            preg_match('~/identity-tombstones/[^/]+/acknowledgement$~', $path) === 1 => IdentityReconciliationController::class.'@acknowledge',
            default => throw new LogicException('Unexpected acceptance provider endpoint.'),
        };
        $this->assertSame($action, $route->getActionName(), 'Actual action: '.$route->getActionName());
        if (str_starts_with($path, '/auth/api/reconciliation/')) {
            $this->assertContains(AuthenticateReconciliationClient::class, $middleware);
        }
        // Enter the real context first so resolving the token guard uses provider configuration.
        // The remaining registered route middleware runs without recursively rebinding the host
        // kernel's current request/session while its identity middleware awaits this response.
        $response = app(EnterEmbeddedContext::class)->handle($request, function (Request $request) use ($outgoing, $path, $middleware): Response {
            $this->assertTrue(app(EmbeddedContext::class)->active());
            $this->assertSame('auth-manager', DB::getDefaultConnection());
            if ($path === '/auth/api/oauth/user') {
                Auth::guard('auth-manager-api')->setRequest($request);
            }

            return app(Pipeline::class)->send($request)
                ->through(array_values(array_diff($middleware, [EnterEmbeddedContext::class])))
                ->then(function (Request $request) use ($outgoing, $path): Response {
                    return match (true) {
                        $path === '/auth/oauth/token' => app(AccessTokenController::class)->issueToken(
                            (new ServerRequest($outgoing->method(), $outgoing->url()))->withParsedBody($outgoing->data()), new PsrResponse),
                        $path === '/auth/api/oauth/user' => app(OAuthUserController::class)($request),
                        $path === '/auth/api/reconciliation/identity-status' => app(IdentityStatusController::class)($request),
                        $path === '/auth/api/reconciliation/identity-tombstones' => app(IdentityReconciliationController::class)->index($request),
                        default => app(IdentityReconciliationController::class)->acknowledge($request, $request->route('tombstone')),
                    };
                });
        });
        $this->assertSame($this->hostDatabase, DB::getDefaultConnection());

        return Http::response($response->getContent(), $response->getStatusCode(), $response->headers->all());
    }

    protected function signInConsumer(): void
    {
        $start = $this->freshRequest()->get('/login')->assertRedirect();
        $host = $start->getCookie((string) config('session.cookie'));
        $this->assertNotNull($host);
        $authorize = (string) $start->headers->get('Location');
        $page = $this->freshRequest()->get($authorize)->assertRedirect();
        $cookie = $page->getCookie('auth_manager_session');
        $this->assertNotNull($cookie);
        $login = $this->freshRequest()->withCookie('auth_manager_session', (string) $cookie->getValue())
            ->post('/auth/login', ['email' => $this->provider->email, 'password' => self::PASSWORD])->assertRedirect();
        $providerCookie = $login->getCookie('auth_manager_session');
        $this->assertNotNull($providerCookie);
        $this->providerCookie = $providerCookie;
        $approved = $this->freshRequest()->withCookie('auth_manager_session', (string) $this->providerCookie->getValue())
            ->get($authorize)->assertRedirect();
        $callback = (string) $approved->headers->get('Location');
        $response = $this->freshRequest()->withCookie($host->getName(), (string) $host->getValue())->get($callback)->assertRedirect();
        $this->assertAuthenticatedAs($this->consumer, 'web');
        $consumerCookie = $response->getCookie((string) config('session.cookie'));
        $this->assertNotNull($consumerCookie);
        $this->consumerCookie = $consumerCookie;
        $this->assertSame(0, session('bherila_auth.provider_session.generation'));
    }

    protected function consumerRequest(string $method, string $path, array $data = []): TestResponse
    {
        $response = $this->freshRequest()->withCredentials()->withCookie($this->consumerCookie->getName(), (string) $this->consumerCookie->getValue())
            ->json($method, $path, $data);
        $this->consumerCookie = $response->getCookie((string) config('session.cookie')) ?? $this->consumerCookie;

        return $response;
    }

    protected function mutateProvider(string $mutation): void
    {
        if ($mutation === 'password') {
            $this->providerRequest('PUT', '/auth/settings/password', [
                'current_password' => self::PASSWORD, 'password' => 'new acceptance password',
                'password_confirmation' => 'new acceptance password',
            ], $this->providerCookie)->assertOk();
        } elseif ($mutation === 'reset') {
            config(['auth-manager.sign_in.password_reset' => true]);
            Mail::fake();
            $this->providerRequest('POST', '/auth/forgot-password', ['email' => $this->provider->email], $this->providerCookie)->assertRedirect();
            $mail = Mail::sent(PasswordResetConfirmationMail::class)->sole();
            $this->assertSame($this->provider->email, $mail->user->email);
            $token = basename((string) parse_url($mail->resetUrl, PHP_URL_PATH));
            $this->providerRequest('POST', '/auth/reset-password', [
                'token' => $token, 'email' => $this->provider->email,
                'password' => 'new acceptance password', 'password_confirmation' => 'new acceptance password',
            ], $this->providerCookie)->assertRedirect(self::ORIGIN.'/auth/login');
            $this->assertGuest('auth-manager');
        } else {
            $cookie = $this->providerAdministrator();
            $path = '/auth/api/admin/users/'.$this->provider->getKey();
            if ($mutation === 'disable') {
                $this->providerRequest('POST', $path.'/disable', [], $cookie)->assertOk();
            } elseif ($mutation === 'delete') {
                $this->providerRequest('DELETE', $path, [], $cookie)->assertAccepted();
            } else {
                throw new LogicException('Unsupported acceptance mutation.');
            }
        }
    }

    protected function providerRequest(string $method, string $path, array $data, Cookie $cookie): TestResponse
    {
        return $this->freshRequest()->withCredentials()->withCookie($cookie->getName(), (string) $cookie->getValue())->json($method, $path, $data);
    }

    protected function providerAdministrator(): Cookie
    {
        $admin = app(EmbeddedContext::class)->run(fn () => ProviderUser::factory()->create(['user_role' => 'admin', 'password' => self::PASSWORD]));
        $page = $this->freshRequest()->get('/auth/login');
        $cookie = $page->getCookie('auth_manager_session');
        $this->assertNotNull($cookie);
        $login = $this->freshRequest()->withCookie('auth_manager_session', (string) $cookie->getValue())
            ->post('/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect();
        $cookie = $login->getCookie('auth_manager_session');
        $this->assertNotNull($cookie);

        return $cookie;
    }
}
