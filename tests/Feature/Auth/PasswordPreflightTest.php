<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use BWH\Auth\Contracts\AuthAuditLogger;
use BWH\Auth\Contracts\LoginThrottle;
use BWH\Auth\Models\TwoFactorAttempt;
use BWH\Auth\Services\PasswordCredentials;
use BWH\Auth\Support\LoginThrottleState;
use BWH\Auth\Support\PasswordCredentialResult;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_endpoint_delegates_to_shared_preflight_with_one_throttle_and_failure_audit(): void
    {
        $this->mock(LoginThrottle::class)->shouldReceive('inspect')->once()->andReturn(LoginThrottleState::allowed(false, 0, 5, null, 'person@example.test', '127.0.0.1', 'password'));
        $this->mock(PasswordCredentials::class)->shouldReceive('verify')->once()
            ->with(Mockery::type(Request::class), ['email' => 'person@example.test', 'password' => 'synthetic-password'])
            ->andReturn(PasswordCredentialResult::invalid());
        $this->mock(AuthAuditLogger::class)->shouldReceive('loginFailed')->once()
            ->with(Mockery::type(Request::class), null, 'person@example.test', 'invalid_credentials');

        $this->postJson('/login', ['email' => 'person@example.test', 'password' => 'synthetic-password', 'remember' => true])->assertUnprocessable();

        $this->assertGuest();
        $this->assertDatabaseCount('auth_two_factor_attempts', 0);
    }

    public function test_accepted_credentials_rehash_but_never_skip_the_email_factor_or_remember_early(): void
    {
        Mail::fake();
        Event::fake([Login::class]);
        $user = User::factory()->approved()->create(['remember_token' => null]);
        $old = Hash::make('synthetic-password', ['rounds' => 5]);
        DB::table('users')->where('id', $user->id)->update(['password' => $old]);

        $this->postJson('/login', ['email' => $user->email, 'password' => 'synthetic-password', 'remember' => true])
            ->assertOk()->assertJsonPath('requires_2fa', true);

        $this->assertGuest();
        Event::assertNotDispatched(Login::class);
        $this->assertNull($user->fresh()->remember_token);
        $this->assertNotSame($old, $user->fresh()->password);
        $this->assertTrue(Hash::check('synthetic-password', $user->fresh()->password));
        $attempt = TwoFactorAttempt::where('user_id', $user->id)->sole();
        $this->postJson('/api/auth/two-factor/verify', ['attempt_token' => $attempt->token, 'code' => $attempt->code])->assertOk();
        $this->assertAuthenticatedAs($user);
        Event::assertDispatchedTimes(Login::class, 1);
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_disabled_credentials_do_not_rehash_create_a_challenge_or_emit_login(): void
    {
        Event::fake([Login::class]);
        $user = User::factory()->approved()->disabled()->create();
        $old = Hash::make('synthetic-password', ['rounds' => 5]);
        DB::table('users')->where('id', $user->id)->update(['password' => $old]);

        $this->postJson('/login', ['email' => $user->email, 'password' => 'synthetic-password', 'remember' => true])->assertUnprocessable();

        $this->assertSame($old, $user->fresh()->password);
        $this->assertGuest();
        $this->assertDatabaseCount('auth_two_factor_attempts', 0);
        Event::assertNotDispatched(Login::class);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function gatedAccounts(): array
    {
        return [
            'unverified' => [['email_verified_at' => null], '/email/verify'],
            'pending approval' => [['approved_at' => null], '/pending-approval?source=login'],
            'banned' => [['banned_at' => '2026-01-01 00:00:00'], '/account/banned'],
            'deactivated' => [['deactivated_at' => '2026-01-01 00:00:00'], '/account/deactivated'],
        ];
    }

    #[DataProvider('gatedAccounts')]
    public function test_existing_account_gates_still_apply_after_password_and_email_code(array $attributes, string $destination): void
    {
        Mail::fake();
        $user = User::factory()->approved()->create($attributes);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirectContains('/login/two-factor/');
        $this->assertGuest();
        $attempt = TwoFactorAttempt::where('user_id', $user->id)->sole();
        $this->postJson('/api/auth/two-factor/verify', ['attempt_token' => $attempt->token, 'code' => $attempt->code])->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->get('/feed')->assertRedirect($destination);
    }
}
