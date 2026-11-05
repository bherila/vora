<?php

namespace Tests\Support;

use BWH\AuthManager\Embedded\EmbeddedContext;
use BWH\AuthManager\Models\User as ProviderUser;
use BWH\AuthManager\Services\OAuthClientGrantService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** A real mounted provider, sharing the host's isolated test database. */
abstract class EmbeddedAuthTestCase extends TestCase
{
    use DatabaseMigrations, WithConsoleEvents;

    protected const ORIGIN = 'http://127.0.0.1:4188';

    protected const PASSWORD = 'correct horse battery staple';

    protected string $keysDirectory;

    private string $hostConnection;

    /** @var array<string, array{mixed, mixed, string|false}> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        $this->keysDirectory = sys_get_temp_dir().'/vora-embedded-'.Str::uuid();
        foreach ([
            'AUTH_SIGN_IN' => 'embedded',
            'APP_NAME' => 'Vora Test',
            'APP_URL' => static::ORIGIN,
            'AUTH_MANAGER_OAUTH_ISSUER' => static::ORIGIN.'/auth',
            'AUTH_MANAGER_EMBEDDED_KEYS_PATH' => $this->keysDirectory,
            'AUTH_MANAGER_EMBEDDED_PREFIX' => 'auth',
            'AUTH_MANAGER_EMBEDDED_TABLE_PREFIX' => 'auth_manager_',
            'AUTH_MANAGER_EMBEDDED_DB_CONNECTION' => (string) env('DB_CONNECTION', 'sqlite'),
            'AUTH_MANAGER_EMBEDDED_SESSION_DRIVER' => 'database',
            'AUTH_MANAGER_REQUIRE_CODE_AFTER_PASSWORD' => 'false',
        ] as $key => $value) {
            $this->savedEnvironment[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }

        parent::setUp();

        // A second :memory: PDO would be a second database. Sharing this safe PDO
        // exercises the production arrangement of prefixed tables beside host tables.
        $host = DB::connection();
        $provider = DB::connection('auth-manager');
        $this->assertSame($host->getDriverName(), $provider->getDriverName());
        $this->assertSame($host->getConfig('host'), $provider->getConfig('host'));
        $this->assertSame($host->getConfig('username'), $provider->getConfig('username'));
        // Laravel's parallel CI runner chooses its derived database after boot.
        $provider->setDatabaseName($host->getDatabaseName());
        DB::setDefaultConnection('auth-manager');
        try {
            $this->assertDatabaseIsSafe();
        } finally {
            DB::setDefaultConnection($this->hostConnection);
        }
        $provider->setPdo($host->getPdo());
        $provider->setReadPdo($host->getPdo());
        $this->artisan('auth-manager:migrate', ['--force' => true])->assertSuccessful();

        (new Filesystem)->ensureDirectoryExists($this->keysDirectory, 0700);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $private));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        file_put_contents($this->keysDirectory.'/oauth-private.key', $private);
        file_put_contents($this->keysDirectory.'/oauth-public.key', $details['key']);
        chmod($this->keysDirectory.'/oauth-private.key', 0600);
        chmod($this->keysDirectory.'/oauth-public.key', 0600);
    }

    /**
     * Keep Laravel's parallel database detection while avoiding historical down()
     * migrations. tearDown wipes this isolated test database instead of rollback.
     */
    public function runDatabaseMigrations(): void
    {
        $this->assertDatabaseIsSafe();
        $connection = DB::connection();
        $token = ParallelTesting::token();
        if ($connection->getDriverName() === 'mariadb' && $token !== false) {
            $this->assertSame('vora_ci_test_'.$token, $connection->getDatabaseName(),
                'Parallel embedded tests must never wipe the shared base CI database.');
        }
        // Set only after every guard passes so failed setup cannot wipe a base
        // database during teardown either.
        $this->hostConnection = DB::getDefaultConnection();
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->app, $this->hostConnection)) {
                DB::setDefaultConnection($this->hostConnection);
                $this->assertDatabaseIsSafe();
                $this->artisan('db:wipe', ['--force' => true])->assertSuccessful();
            }
            // These tests deliberately avoid transactions spanning two Laravel
            // connections on one PDO. The next normal test must recreate tables.
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
            parent::tearDown();
        } finally {
            Passport::$keyPath = null;
            foreach ($this->savedEnvironment as $key => [$env, $server, $process]) {
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
            (new Filesystem)->deleteDirectory($this->keysDirectory);
        }
    }

    /** @return array{ProviderUser, Client, string} */
    protected function grantedClient(string $email = 'person@example.test'): array
    {
        return app(EmbeddedContext::class)->run(function () use ($email): array {
            $user = ProviderUser::factory()->create([
                'email' => $email, 'password' => self::PASSWORD, 'user_role' => 'user',
            ]);
            $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Vora', [static::ORIGIN.'/oauth/callback']);
            app(OAuthClientGrantService::class)->grant((string) $user->getKey(), (string) $client->getKey());

            return [$user, $client, (string) $client->plain_secret];
        });
    }

    protected function freshRequest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this;
    }
}
