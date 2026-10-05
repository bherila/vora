<?php

namespace Tests\Browser;

use App\Models\User;
use BWH\Auth\Models\TwoFactorAttempt;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * A test-only request worker for the loopback browser bridge. One PHP process
 * retains the SQLite in-memory connection throughout both browser sessions.
 * This directory is deliberately outside the default PHPUnit test suites.
 */
class BrowserServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_request_worker(): void
    {
        if (getenv('VORA_BROWSER_WORKER') !== '1') {
            $this->markTestSkipped('Started only by the browser test bridge.');
        }

        $this->assertDatabaseIsSafe();
        $this->app->instance(Vite::class, new Vite);
        // Keep real CSRF middleware active instead of Laravel's unit-test bypass.
        $this->app->instance('env', 'browser-testing');
        config([
            'app.url' => 'http://127.0.0.1:4187',
            'session.driver' => 'database',
            'session.connection' => 'sqlite',
            'session.domain' => null,
            'session.secure' => false,
        ]);
        User::factory()->admin()->create(); // Test accounts must not become first-user admins.
        $this->emit(['ready' => true]);
        $requests = 0;

        while (($line = fgets(STDIN)) !== false) {
            $input = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $requests++;

            if ($input['path'] === '/__browser/fixtures') {
                $users = [];
                foreach (['Alice', 'Bob'] as $name) {
                    $user = User::factory()->approved()->create([
                        'display_name' => $name,
                        'is_admin' => false,
                    ]);
                    $users[strtolower($name)] = ['id' => $user->id, 'email' => $user->email];
                }
                $this->emit(['id' => $input['id'], 'status' => 200, 'headers' => [], 'body' => base64_encode(json_encode($users, JSON_THROW_ON_ERROR))]);

                continue;
            }

            if ($input['path'] === '/__browser/two-factor') {
                $email = $input['headers']['x-browser-email'] ?? '';
                $attempt = TwoFactorAttempt::query()
                    ->where('user_id', User::query()->where('email', $email)->value('id'))
                    ->latest('id')->firstOrFail();
                $this->emit(['id' => $input['id'], 'status' => 200, 'headers' => [], 'body' => base64_encode(json_encode(['code' => $attempt->code], JSON_THROW_ON_ERROR))]);

                continue;
            }

            // A fresh request needs fresh auth/session/scoped state. Persisted
            // sessions live in the same in-memory database as the app's data.
            $this->app['auth']->forgetGuards();
            $this->app['session']->forgetDrivers();
            $this->app->forgetInstance('session.store');
            $this->app['cookie']->flushQueuedCookies();
            $this->app->forgetScopedInstances();
            Facade::clearResolvedInstances();

            $server = ['REMOTE_ADDR' => '127.0.0.1', 'SERVER_PORT' => '4187'];
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
            $request = Request::create(
                'http://127.0.0.1:4187'.$input['path'],
                $input['method'], [], $cookies, [], $server,
                base64_decode($input['body'], true),
            );
            $kernel = $this->app->make(Kernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
            $this->emit([
                'id' => $input['id'],
                'status' => $response->getStatusCode(),
                'headers' => $response->headers->allPreserveCaseWithoutCookies(),
                'cookies' => array_map(strval(...), $response->headers->getCookies()),
                'body' => base64_encode($response->getContent()),
            ]);
        }

        $this->assertGreaterThan(0, $requests);
    }

    /** @param array<string, mixed> $message */
    private function emit(array $message): void
    {
        fwrite(STDOUT, 'VORA_BROWSER_JSON '.json_encode($message, JSON_THROW_ON_ERROR)."\n");
    }
}
