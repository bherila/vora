<?php

namespace Tests\Browser;

use App\Models\User;
use BWH\AuthManager\Embedded\EmbeddedContext;
use BWH\AuthManager\Models\User as ProviderUser;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\EmbeddedAuthTestCase;

/**
 * Test-only replicas for real self-HTTP OAuth, each retaining SQLite :memory:.
 * The bridge sends mounted provider requests to one and host requests to the
 * other, so a host callback's token exchange cannot deadlock its own worker.
 */
class EmbeddedBrowserServerTest extends EmbeddedAuthTestCase
{
    protected const ORIGIN = 'https://localhost:4188';

    public function test_embedded_browser_worker(): void
    {
        if (getenv('VORA_EMBEDDED_BROWSER_WORKER') !== '1') {
            $this->markTestSkipped('Started only by the embedded browser test bridge.');
        }

        $this->assertDatabaseIsSafe();
        Http::globalOptions(['verify' => getenv('VORA_EMBEDDED_BROWSER_CA')]);
        $this->app->instance(Vite::class, new Vite);
        $this->app->instance('env', 'browser-testing');
        config([
            'app.url' => self::ORIGIN,
            'session.driver' => 'database', 'session.connection' => 'sqlite',
            'session.domain' => null, 'session.secure' => false,
        ]);

        $provider = getenv('VORA_EMBEDDED_BROWSER_ROLE') === 'provider';
        if ($provider) {
            [$person, $client, $secret] = $this->grantedClient();
            app(EmbeddedContext::class)->run(function () use ($person): void {
                $person->forceFill(['name' => 'Example Person'])->save();
                ProviderUser::factory()->create([
                    'name' => 'Example Administrator', 'email' => 'admin@example.test',
                    'password' => self::PASSWORD, 'user_role' => 'admin',
                ]);
            });
            $this->emit(['ready' => true, 'client' => (string) $client->getKey(), 'secret' => $secret, 'email' => $person->email]);
        } else {
            $this->emit(['ready' => true]);
        }

        $requests = 0;
        while (($line = fgets(STDIN)) !== false) {
            $input = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $requests++;

            if (! $provider && str_starts_with($input['path'], '/__embedded/')) {
                $user = User::withTrashed()->where('email', 'person@example.test')->first();
                if ($input['path'] === '/__embedded/disable') {
                    $user?->forceFill(['is_disabled' => true])->save();
                } elseif ($input['path'] === '/__embedded/delete') {
                    $user?->delete();
                }
                $this->emit([
                    'id' => $input['id'], 'status' => 200, 'headers' => [],
                    'body' => base64_encode(json_encode([
                        'count' => User::withTrashed()->count(),
                        'subject' => $user?->oauth_subject,
                        'provider' => $user?->oauth_provider,
                        'last_login_at' => $user?->last_login_at?->toIso8601String(),
                    ], JSON_THROW_ON_ERROR)),
                ]);

                continue;
            }

            $this->app['auth']->forgetGuards();
            $this->app['session']->forgetDrivers();
            $this->app->forgetInstance('session.store');
            $this->app['cookie']->flushQueuedCookies();
            $this->app->forgetScopedInstances();
            Facade::clearResolvedInstances();

            $server = ['REMOTE_ADDR' => '127.0.0.1', 'SERVER_PORT' => '4188'];
            foreach ($input['headers'] as $name => $value) {
                $key = strtoupper(str_replace('-', '_', $name));
                $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
            }
            $cookies = [];
            foreach (explode(';', $input['headers']['cookie'] ?? '') as $cookie) {
                if (str_contains($cookie, '=')) {
                    [$name, $value] = explode('=', trim($cookie), 2);
                    $cookies[$name] = urldecode($value);
                }
            }
            $content = base64_decode($input['body'], true);
            $parameters = [];
            if (str_starts_with($server['CONTENT_TYPE'] ?? '', 'application/x-www-form-urlencoded')) {
                parse_str($content, $parameters);
            }
            $request = Request::create(self::ORIGIN.$input['path'], $input['method'], $parameters, $cookies, [], $server, $content);
            $kernel = $this->app->make(Kernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
            if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
                ob_start();
                $response->sendContent();
                $body = ob_get_clean();
            } else {
                $body = $response->getContent();
            }
            $this->emit([
                'id' => $input['id'], 'status' => $response->getStatusCode(),
                'headers' => $response->headers->allPreserveCaseWithoutCookies(),
                'cookies' => array_map(strval(...), $response->headers->getCookies()),
                'body' => base64_encode($body),
            ]);
        }

        $this->assertGreaterThan(0, $requests);
    }

    /** @param array<string, mixed> $message */
    private function emit(array $message): void
    {
        fwrite(STDOUT, 'VORA_EMBEDDED_JSON '.json_encode($message, JSON_THROW_ON_ERROR)."\n");
    }
}
