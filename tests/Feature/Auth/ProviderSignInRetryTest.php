<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use PDOException;
use Tests\TestCase;

/**
 * Laravel never retries a nested transaction, so this migrates its own
 * in-memory database instead of running inside the transaction
 * RefreshDatabase wraps around each test.
 */
class ProviderSignInRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_a_deadlocked_first_sign_in_is_retried(): void
    {
        config([
            'auth.sign_in' => 'provider',
            'bherila-auth.oauth_client.provider' => 'example',
            'bherila-auth.oauth_client.base_url' => 'https://id.example.test',
            'bherila-auth.oauth_client.client_id' => 'client-1',
            'bherila-auth.oauth_client.client_secret' => 'secret-1',
            'bherila-auth.oauth_client.redirect_uri' => 'http://localhost/oauth/callback',
        ]);
        Http::fake([
            'https://id.example.test/oauth/token' => Http::response(['access_token' => 'access-1', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            'https://id.example.test/api/oauth/user' => Http::response(['sub' => '42', 'name' => 'Avery Example', 'email' => 'avery@example.test']),
        ]);
        // MariaDB answers two concurrent first sign-ins with a deadlock, not a
        // unique-index violation.
        $deadlocked = false;
        User::creating(function () use (&$deadlocked): void {
            if (! $deadlocked) {
                $deadlocked = true;
                throw new QueryException('mysql', 'insert into users', [], new PDOException('Deadlock found when trying to get lock; try restarting transaction'));
            }
        });

        parse_str((string) parse_url((string) $this->get('/login')->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get('/oauth/callback?'.http_build_query(['code' => 'code-1', 'state' => $query['state']]))->assertRedirect('/');

        $this->assertTrue($deadlocked);
        $this->assertSame('42', User::sole()->oauth_subject);
    }
}
