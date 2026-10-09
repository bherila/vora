<?php

namespace Tests\Browser;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\Media;
use App\Models\Story;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\Story\StoryService;
use BWH\Auth\Models\TwoFactorAttempt;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        $admin = User::factory()->admin()->create(['display_name' => 'Browser Admin']);
        Notification::fake([VerifyEmail::class]);
        // Object storage is faked; media requests still use normal policies
        // and protected asset routes, including after access is revoked.
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', true);
        $storage = \Mockery::mock(FileStorageService::class);
        $storage->shouldReceive('getSignedViewUrl')->andReturnUsing(function (string $disk, string $key): string {
            $media = Media::query()->where('object_key', $key)->firstOrFail();

            return route('media.asset', ['ulid' => $media->ulid, 'variant' => 'original'], false);
        });
        $storage->shouldReceive('getFileSize')->andReturn(strlen($image));
        // Missing mappings represent videos still processing. One deliberately
        // invalid manifest exercises the real player's fatal-error handling.
        $storage->shouldReceive('get')->andReturnUsing(static function (string $disk, string $key): ?string {
            return $key === 'by-id/browser-broken/master.m3u8' ? 'Invalid browser-test manifest' : null;
        });
        $storage->shouldReceive('readStream')->andReturnUsing(static function () use ($image): mixed {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $image);
            rewind($stream);

            return $stream;
        });
        $this->app->instance(FileStorageService::class, $storage);
        $this->emit(['ready' => true]);
        $requests = 0;

        while (($line = fgets(STDIN)) !== false) {
            $input = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $requests++;

            if ($input['path'] === '/__browser/registration') {
                $this->emitJson($input['id'], [
                    'admin' => ['id' => $admin->id, 'email' => $admin->email],
                    'email' => "browser-signup-{$requests}@example.test",
                ]);

                continue;
            }

            if ($input['path'] === '/__browser/verification-link') {
                $user = User::query()->where('email', $input['headers']['x-browser-email'] ?? '')->firstOrFail();
                $notification = Notification::sent($user, VerifyEmail::class)->last();
                $this->assertInstanceOf(VerifyEmail::class, $notification);
                $this->emitJson($input['id'], ['url' => $notification->toMail($user)->actionUrl]);

                continue;
            }

            if ($input['path'] === '/__browser/media-fixtures') {
                $persona = Character::query()->findOrFail($input['headers']['x-browser-persona'] ?? '');
                $media = [];
                foreach (['account' => null, 'persona' => $persona->id] as $name => $characterId) {
                    $item = Media::factory()->approved()->create([
                        'user_id' => $persona->user_id,
                        'character_id' => $characterId,
                        'title' => "{$name} followers image",
                        'mime_type' => 'image/png',
                        'size_bytes' => strlen($image),
                        'audience' => Audience::Followers,
                    ]);
                    $media[$name] = ['ulid' => $item->ulid, 'title' => $item->title];
                }
                $this->emitJson($input['id'], $media);

                continue;
            }

            if ($input['path'] === '/__browser/content-fixtures') {
                $owner = User::query()->findOrFail($input['headers']['x-browser-owner'] ?? '');
                $longForm = Story::factory()->for($owner)->readable()->create([
                    'title' => 'The lighthouse journal',
                    'body' => "## A quiet harbor\n\nThe lantern guided us home.",
                ]);
                $adventure = Story::factory()->for($owner)->cyoa()->readable()->create([
                    'title' => 'Paths through the harbor',
                ]);
                $this->app->make(StoryService::class)->saveGraph($adventure, [
                    ['key' => 'start', 'title' => 'The crossroads', 'body' => 'Choose your route.', 'is_start' => true],
                    ['key' => 'dock', 'title' => 'At the dock', 'body' => 'A boat waits in the moonlight.'],
                ], [
                    ['from' => 'start', 'to' => 'dock', 'label' => 'Visit the dock', 'position' => 0],
                    ['from' => 'start', 'to' => null, 'label' => 'Stay ashore', 'position' => 1],
                ]);
                $media = [
                    'pending' => Media::factory()->for($owner)->create(['title' => 'Photo awaiting review']),
                    'rejected' => Media::factory()->for($owner)->rejected()->create([
                        'title' => 'Photo withheld from readers',
                        'moderation_notes' => 'Private moderator note',
                    ]),
                    'uploading' => Media::factory()->for($owner)->pendingUpload()->create(['title' => 'Unfinished upload']),
                    'processing' => Media::factory()->for($owner)->video()->approved()->create(['title' => 'Video awaiting transcoding']),
                    'broken' => Media::factory()->for($owner)->video()->approved()->create([
                        'title' => 'Video with unavailable stream',
                        'hls_content_id' => 'browser-broken',
                    ]),
                ];
                $this->emitJson($input['id'], [
                    'admin' => ['id' => $admin->id, 'email' => $admin->email],
                    'stories' => [
                        'longForm' => ['id' => $longForm->id, 'ulid' => $longForm->ulid],
                        'adventure' => ['id' => $adventure->id, 'ulid' => $adventure->ulid],
                    ],
                    'media' => array_map(static fn (Media $item): array => ['id' => $item->id, 'ulid' => $item->ulid, 'title' => $item->title], $media),
                ]);

                continue;
            }

            if ($input['path'] === '/__browser/fixtures') {
                $users = [];
                foreach (['Alice', 'Bob'] as $name) {
                    $suffix = $input['headers']['x-browser-name-suffix'] ?? '';
                    $user = User::factory()->approved()->create([
                        'display_name' => trim($name.' '.$suffix),
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
            if ($response instanceof StreamedResponse) {
                ob_start();
                $response->sendContent();
                $body = ob_get_clean();
            } else {
                $body = $response->getContent();
            }
            $this->emit([
                'id' => $input['id'],
                'status' => $response->getStatusCode(),
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
        fwrite(STDOUT, 'VORA_BROWSER_JSON '.json_encode($message, JSON_THROW_ON_ERROR)."\n");
    }

    /** @param array<string, mixed> $data */
    private function emitJson(int $id, array $data): void
    {
        $this->emit(['id' => $id, 'status' => 200, 'headers' => [], 'body' => base64_encode(json_encode($data, JSON_THROW_ON_ERROR))]);
    }
}
