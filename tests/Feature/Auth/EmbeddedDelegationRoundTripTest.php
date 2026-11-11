<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use BWH\Auth\Http\Controllers\DelegatedAccessController;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedAccessSettings;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedContract;
use BWH\Auth\OAuth\DelegatedAccess\NonceStore;
use BWH\AuthManager\Embedded\EmbeddedContext;
use BWH\AuthManager\Models\RegisteredApplication;
use BWH\AuthManager\Models\User as ProviderUser;
use BWH\AuthManager\Services\DelegatedAccess\ActorAssertion;
use BWH\AuthManager\Services\OAuthClientGrantService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\EmbeddedAuthTestCase;

/** Real provider controllers and signatures; only the network hop is in-process. */
class EmbeddedDelegationRoundTripTest extends EmbeddedAuthTestCase
{
    protected const ORIGIN = 'https://application.example.test';

    private ?string $providerCookie = null;

    /** @var list<array{body:string, assertion:string, status:int}> */
    private array $exchanges = [];

    public function test_mounted_provider_can_manage_only_what_the_verified_host_administrator_may_change(): void
    {
        [$actor, $client] = $this->grantedClient();
        $subject = app(EmbeddedContext::class)->run(function () use ($actor, $client): string {
            $actor->forceFill(['user_role' => 'user,access-manage:example-app'])->save();
            $application = RegisteredApplication::create(['key' => 'example-app', 'name' => 'Example application', 'launch_url' => self::ORIGIN, 'enabled' => true]);
            $application->clients()->attach($client->getKey());
            $target = ProviderUser::factory()->create(['user_role' => 'user']);
            app(OAuthClientGrantService::class)->grant((string) $target->id, (string) $client->getKey());

            return (string) $target->id;
        });
        User::factory()->admin()->create(['id' => 1]);
        $manager = User::factory()->admin()->create(['oauth_provider' => 'example', 'oauth_subject' => (string) $actor->id]);
        $target = User::factory()->approved()->create(['oauth_provider' => 'example', 'oauth_subject' => $subject]);
        $this->configureIntegration();
        $hostConnection = DB::getDefaultConnection();
        Http::preventStrayRequests();
        Http::fake(fn (ClientRequest $request) => $this->hostTransport($request));

        $this->providerBrowser('GET', '/auth/login')->assertOk();
        $this->providerBrowser('POST', '/auth/login', ['email' => $actor->email, 'password' => self::PASSWORD])->assertRedirect();
        $this->assertGuest('web');
        $this->assertAuthenticatedAs($actor, 'auth-manager');
        $this->travel(6)->minutes();
        $path = '/auth/applications/example-app/access';
        $state = $this->providerBrowser('GET', $path.'?subject='.$subject)->assertOk()->viewData('state');
        $change = ['subject' => $subject, 'expected_revision' => $state['revision'], 'application_admin' => true,
            'workspaces' => [], 'operation_id' => DelegatedContract::operationId()];
        // A stale password presentation is not recent confirmation for a write.
        $this->providerBrowser('POST', $path.'/update', $change)->assertRedirect();
        $this->assertFalse($target->fresh()->is_admin);
        $this->assertDatabaseCount('auth_audit_log', 0);

        $this->providerBrowser('POST', $path.'/confirm', ['password' => self::PASSWORD])->assertRedirect();
        $this->providerBrowser('POST', $path.'/update', $change)->assertRedirect();
        $this->assertTrue($target->fresh()->is_admin);
        $this->assertDatabaseHas('auth_audit_log', ['event' => 'delegated_application_access_update', 'acting_user_id' => $manager->id, 'user_id' => $target->id]);
        $this->assertDatabaseHas('auth_manager_audit_log', ['event' => 'delegated_access_update_result', 'acting_user_id' => $actor->id, 'succeeded' => true]);
        $providerAudit = json_decode(DB::table('auth_manager_audit_log')->where('event', 'delegated_access_update_result')->value('metadata'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('succeeded', $providerAudit['outcome']);
        $this->assertGuest('web');
        $this->assertSame($hostConnection, DB::getDefaultConnection());

        // Provider permission and current application grant confer no host role.
        $manager->forceFill(['is_admin' => false])->save();
        $this->providerBrowser('GET', $path.'?subject='.$subject)->assertForbidden();
        $this->assertTrue($target->fresh()->is_admin);
        $this->assertSame(403, $this->exchanges[array_key_last($this->exchanges)]['status']);

        $successful = collect($this->exchanges)->firstWhere('status', 200);
        $this->assertNotNull($successful);
        $this->hostRequest($successful['assertion'], $successful['body'])->assertUnauthorized()->assertJsonPath('error', 'replayed_actor_assertion');
        $wrongIssuer = app(ActorAssertion::class)->issue(self::ORIGIN.'/another-provider', (string) $actor->id,
            self::ORIGIN.'/application-access', 'example-app', $successful['body'], 'example-app-key',
            file_get_contents($this->keysDirectory.'/delegated-private.pem'));
        $this->hostRequest($wrongIssuer, $successful['body'])->assertUnauthorized()->assertJsonPath('error', 'invalid_actor_assertion');
    }

    private function configureIntegration(): void
    {
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($pair);
        $this->assertTrue(openssl_pkey_export($pair, $private));
        $privatePath = $this->keysDirectory.'/delegated-private.pem';
        $publicPath = $this->keysDirectory.'/delegated-public.pem';
        file_put_contents($privatePath, $private);
        chmod($privatePath, 0600);
        file_put_contents($publicPath, openssl_pkey_get_details($pair)['key']);
        config(['bherila-auth.oauth_client.provider' => 'example', 'bherila-auth.oauth_client.base_url' => self::ORIGIN.'/auth',
            'bherila-auth.delegated_access.enabled' => true, 'bherila-auth.delegated_access.writes_enabled' => true,
            'bherila-auth.delegated_access.oauth_provider' => 'example', 'bherila-auth.delegated_access.issuer' => self::ORIGIN.'/auth',
            'bherila-auth.delegated_access.endpoint' => self::ORIGIN.'/application-access',
            'bherila-auth.delegated_access.application' => 'example-app', 'bherila-auth.delegated_access.public_keys' => 'example-app-key|'.$publicPath,
            'auth-manager-embedded.overlay.delegated-access.enabled' => true,
            'auth-manager-embedded.overlay.delegated-access.writes_enabled' => true,
            'auth-manager-embedded.overlay.delegated-access.writes_applications' => ['example-app'],
            'auth-manager-embedded.overlay.delegated-access.issuer' => self::ORIGIN.'/auth',
            'auth-manager-embedded.overlay.delegated-access.keys_environment' => 'example-app|example-app-key|'.$privatePath,
            'auth-manager-embedded.overlay.delegated-access.applications' => ['example-app' => ['endpoint' => self::ORIGIN.'/application-access', 'contract_version' => 3]],
        ]);
        // The production store refuses non-durable :memory:. Keep nonce/replay
        // semantics in this fixture without changing that production guard.
        app()->instance(NonceStore::class, new class implements NonceStore
        {
            /** @var array<string, true> */
            private array $seen = [];

            public function consume(string $key, int $seconds): bool
            {
                if (isset($this->seen[$key])) {
                    return false;
                }

                return $this->seen[$key] = true;
            }
        });
    }

    private function hostTransport(ClientRequest $outgoing): mixed
    {
        $this->assertSame(self::ORIGIN.'/application-access', $outgoing->url());
        $this->assertSame('POST', $outgoing->method());
        $context = app(EmbeddedContext::class);
        $this->assertTrue($context->active());
        $providerSession = app('session.store');
        $context->leave();
        $this->assertFalse($context->active(), 'Exactly one provider request context surrounds this transport.');
        try {
            $server = [];
            foreach ($outgoing->headers() as $name => $values) {
                $key = strtoupper(str_replace('-', '_', $name));
                $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = implode(', ', $values);
            }
            $incoming = Request::create($outgoing->url(), $outgoing->method(), [], [], [], $server, $outgoing->body());
            $route = app('router')->getRoutes()->match($incoming);
            $incoming->setRouteResolver(fn () => $route);
            $this->assertSame(DelegatedAccessController::class, $route->getActionName());
            $middleware = app('router')->gatherRouteMiddleware($route);
            $this->assertNotContains('web', $middleware);
            // Avoid recursively entering the global HTTP kernel. The actual
            // registered route middleware and controller receive a fresh request.
            $response = app(Pipeline::class)->send($incoming)->through($middleware)->then(fn (Request $request): Response => app(DelegatedAccessController::class)($request, app(DelegatedAccessSettings::class), app(), app('config')));
            $this->exchanges[] = ['body' => $outgoing->body(), 'assertion' => substr($outgoing->header('Authorization')[0], 7), 'status' => $response->getStatusCode()];
        } finally {
            $context->enter();
            $this->assertTrue($context->active());
            $this->assertSame($providerSession, app('session.store'));
            $this->assertSame('auth-manager', DB::getDefaultConnection());
        }

        return Http::response($response->getContent(), $response->getStatusCode(), $response->headers->all());
    }

    /** @param array<string, mixed> $data */
    private function providerBrowser(string $method, string $path, array $data = []): TestResponse
    {
        $this->freshRequest();
        if ($this->providerCookie !== null) {
            $this->withCookie('auth_manager_session', $this->providerCookie);
        }
        $response = $this->call($method, $path, $data, $this->prepareCookiesForRequest());
        $cookie = $response->getCookie('auth_manager_session');
        if ($cookie !== null) {
            $this->providerCookie = (string) $cookie->getValue();
        }

        return $response;
    }

    private function hostRequest(string $assertion, string $body): TestResponse
    {
        return $this->freshRequest()->call('POST', '/application-access', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$assertion,
        ], $body);
    }
}
