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
use Closure;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Auth\Middleware\Authenticate;
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

    /** @var array<string, Cookie> Raw response cookies, keyed by domain/path/name. */
    private array $browserCookies = [];

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
        // A nested transport must give the bearer guard its own request. Insert only
        // this fixture binding at its use site; preserve every registered middleware's
        // order, including context entry, authentication and OAuth policy enforcement.
        $pipeline = [];
        foreach ($middleware as $entry) {
            if ($entry === Authenticate::class.':auth-manager-api') {
                $pipeline[] = function (Request $request, Closure $next): Response {
                    $this->assertTrue(app(EmbeddedContext::class)->active());
                    Auth::guard('auth-manager-api')->setRequest($request);

                    return $next($request);
                };
            }
            $pipeline[] = $entry;
        }
        $response = app(Pipeline::class)->send($request)->through($pipeline)
            ->then(function (Request $request) use ($outgoing, $path): Response {
                $this->assertTrue(app(EmbeddedContext::class)->active());
                $this->assertSame('auth-manager', DB::getDefaultConnection());

                return match (true) {
                    $path === '/auth/oauth/token' => app(AccessTokenController::class)->issueToken(
                        (new ServerRequest($outgoing->method(), $outgoing->url()))->withParsedBody($outgoing->data()), new PsrResponse),
                    $path === '/auth/api/oauth/user' => app(OAuthUserController::class)($request),
                    $path === '/auth/api/reconciliation/identity-status' => app(IdentityStatusController::class)($request),
                    $path === '/auth/api/reconciliation/identity-tombstones' => app(IdentityReconciliationController::class)->index($request),
                    default => app(IdentityReconciliationController::class)->acknowledge($request, $request->route('tombstone')),
                };
            });
        $this->assertSame($this->hostDatabase, DB::getDefaultConnection());

        return Http::response($response->getContent(), $response->getStatusCode(), $response->headers->all());
    }

    /**
     * Keep raw response cookies as a browser would, including cookies not named by
     * this fixture. Host-only and path matching matters: the host cookie goes to
     * /auth too, while the provider's /auth cookie cannot reach host application APIs.
     */
    private function browserRequest(string $method, string $uri, array $data = [], bool $json = false): TestResponse
    {
        $url = str_starts_with($uri, '/') ? self::ORIGIN.$uri : $uri;
        $this->assertSame(parse_url(self::ORIGIN, PHP_URL_HOST), parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $cookies = [];
        foreach ($this->browserCookies as $cookie) {
            $cookiePath = $cookie->getPath();
            if (($cookie->getExpiresTime() === 0 || $cookie->getExpiresTime() > now()->timestamp)
                && (! $cookie->isSecure() || parse_url($url, PHP_URL_SCHEME) === 'https')
                && ($path === $cookiePath || str_starts_with($path, rtrim($cookiePath, '/').'/'))) {
                $cookies[$cookie->getName()] = (string) $cookie->getValue();
            }
        }
        $this->freshRequest()->withCredentials()->withUnencryptedCookies($cookies);
        $response = $json ? $this->json($method, $url, $data) : $this->call($method, $url, $data, $this->prepareCookiesForRequest());
        foreach ($response->headers->getCookies() as $cookie) {
            $domain = $cookie->getDomain() ?: (string) parse_url($url, PHP_URL_HOST);
            $this->assertSame((string) parse_url(self::ORIGIN, PHP_URL_HOST), ltrim($domain, '.'));
            $this->browserCookies[$domain.'|'.$cookie->getPath().'|'.$cookie->getName()] = $cookie;
        }

        return $response;
    }

    protected function signInConsumer(): void
    {
        $start = $this->browserRequest('GET', '/login')->assertRedirect();
        $host = $start->getCookie((string) config('session.cookie'));
        $this->assertNotNull($host);
        $payload = DB::table('sessions')->where('id', $host->getValue())->value('payload');
        $this->assertNotNull($payload);
        $authorize = (string) $start->headers->get('Location');
        $this->browserRequest('GET', $authorize)->assertRedirect();
        $this->providerRequest('POST', '/auth/login', ['email' => $this->provider->email, 'password' => self::PASSWORD])->assertRedirect();
        $approved = $this->providerRequest('GET', $authorize, [], false)->assertRedirect();
        $this->assertSame($payload, DB::table('sessions')->where('id', $host->getValue())->value('payload'));
        $callback = (string) $approved->headers->get('Location');
        $this->browserRequest('GET', $callback)->assertRedirect();
        $this->assertAuthenticatedAs($this->consumer, 'web');
        $this->assertSame(0, session('bherila_auth.provider_session.generation'));
    }

    protected function consumerRequest(string $method, string $path, array $data = []): TestResponse
    {
        return $this->browserRequest($method, $path, $data, true);
    }

    protected function mutateProvider(string $mutation): void
    {
        if ($mutation === 'password') {
            $this->providerRequest('PUT', '/auth/settings/password', [
                'current_password' => self::PASSWORD, 'password' => 'new acceptance password',
                'password_confirmation' => 'new acceptance password',
            ])->assertOk();
        } elseif ($mutation === 'reset') {
            config(['auth-manager.sign_in.password_reset' => true]);
            Mail::fake();
            $this->providerRequest('POST', '/auth/forgot-password', ['email' => $this->provider->email])->assertRedirect();
            $mail = Mail::sent(PasswordResetConfirmationMail::class)->sole();
            $this->assertSame($this->provider->email, $mail->user->email);
            $token = basename((string) parse_url($mail->resetUrl, PHP_URL_PATH));
            $this->providerRequest('POST', '/auth/reset-password', [
                'token' => $token, 'email' => $this->provider->email,
                'password' => 'new acceptance password', 'password_confirmation' => 'new acceptance password',
            ])->assertRedirect(self::ORIGIN.'/auth/login');
            $this->assertGuest('auth-manager');
        } else {
            $this->providerAdministrator();
            $path = '/auth/api/admin/users/'.$this->provider->getKey();
            if ($mutation === 'disable') {
                $this->providerRequest('POST', $path.'/disable', [])->assertOk();
            } elseif ($mutation === 'delete') {
                $this->providerRequest('DELETE', $path, [])->assertAccepted();
            } else {
                throw new LogicException('Unsupported acceptance mutation.');
            }
        }
    }

    protected function providerRequest(string $method, string $path, array $data = [], bool $json = true): TestResponse
    {
        $hostCookieName = (string) config('session.cookie');
        $hostCookies = array_filter($this->browserCookies, fn (Cookie $cookie): bool => $cookie->getName() === $hostCookieName);
        $hostSessions = DB::table('sessions')->orderBy('id')->get()->toArray();
        $response = $this->browserRequest($method, $path, $data, $json);
        $this->assertEquals($hostCookies, array_filter($this->browserCookies, fn (Cookie $cookie): bool => $cookie->getName() === $hostCookieName));
        $this->assertEquals($hostSessions, DB::table('sessions')->orderBy('id')->get()->toArray());

        return $response;
    }

    protected function providerAdministrator(): void
    {
        $admin = app(EmbeddedContext::class)->run(fn () => ProviderUser::factory()->create(['user_role' => 'admin', 'password' => self::PASSWORD]));
        $this->providerRequest('GET', '/auth/login', [], false)->assertOk();
        $this->providerRequest('POST', '/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect();
    }
}
