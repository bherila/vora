<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\LimitTwoFactorGuesses;
use App\Models\User;
use BWH\Auth\Models\TwoFactorAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function challenge(User $user): TwoFactorAttempt
    {
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        return TwoFactorAttempt::where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    private function wrongCode(TwoFactorAttempt $attempt): string
    {
        return $attempt->code === '000000' ? '111111' : '000000';
    }

    #[Test]
    public function a_challenge_is_spent_after_five_wrong_codes(): void
    {
        Mail::fake();
        $user = User::factory()->approved()->create();
        $attempt = $this->challenge($user);

        foreach (range(1, LimitTwoFactorGuesses::MAX_WRONG_CODES) as $ignored) {
            $this->postJson('/api/auth/two-factor/verify', ['attempt_token' => $attempt->token, 'code' => $this->wrongCode($attempt)])
                ->assertStatus(422);
        }

        $this->assertTrue($attempt->fresh()->is_used);
        $this->postJson('/api/auth/two-factor/verify', ['attempt_token' => $attempt->token, 'code' => $attempt->code])
            ->assertStatus(422);
        $this->assertGuest();
    }

    #[Test]
    public function the_right_code_still_works_after_a_few_misses(): void
    {
        Mail::fake();
        $user = User::factory()->approved()->create();
        $attempt = $this->challenge($user);

        foreach (range(1, LimitTwoFactorGuesses::MAX_WRONG_CODES - 1) as $ignored) {
            $this->postJson('/api/auth/two-factor/verify', ['attempt_token' => $attempt->token, 'code' => $this->wrongCode($attempt)])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/two-factor/verify', ['attempt_token' => $attempt->token, 'code' => $attempt->code])
            ->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function verify_is_rate_limited_per_ip(): void
    {
        foreach (range(1, 60) as $attempt) {
            $this->postJson('/api/auth/two-factor/verify', ['attempt_token' => "missing-{$attempt}", 'code' => '123456'])->assertStatus(422);
        }

        $this->postJson('/api/auth/two-factor/verify', ['attempt_token' => 'missing-next', 'code' => '123456'])->assertStatus(429);
    }

    #[Test]
    public function verify_is_rate_limited_per_challenge_token(): void
    {
        foreach (range(1, 10) as $ignored) {
            $this->postJson('/api/auth/two-factor/verify', ['attempt_token' => 'missing', 'code' => '123456'])->assertStatus(422);
        }

        $this->postJson('/api/auth/two-factor/verify', ['attempt_token' => 'missing', 'code' => '123456'])->assertStatus(429);
        $this->postJson('/api/auth/two-factor/verify', ['attempt_token' => 'other', 'code' => '123456'])->assertStatus(422);
    }

    #[Test]
    public function resending_codes_is_limited_per_account(): void
    {
        Mail::fake();
        $user = User::factory()->approved()->create();
        $token = $this->challenge($user)->token;

        foreach (range(1, 3) as $attempt) {
            $token = $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$attempt}"])
                ->postJson('/api/auth/two-factor/resend', ['attempt_token' => $token])
                ->assertOk()->json('attempt_token');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/auth/two-factor/resend', ['attempt_token' => $token])->assertStatus(429);
    }

    #[Test]
    public function forgot_password_is_limited_per_address_across_ips(): void
    {
        Notification::fake();
        Mail::fake();
        $user = User::factory()->approved()->create();

        foreach (range(1, 5) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$attempt}"])
                ->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertSuccessful();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/auth/forgot-password', ['email' => strtoupper($user->email)])->assertStatus(429);
    }

    #[Test]
    public function registration_is_rate_limited_per_address(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$attempt}"])
                ->postJson('/api/auth/register', ['email' => 'repeat@example.com'])->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/auth/register', ['email' => 'repeat@example.com'])->assertStatus(429);
    }

    #[Test]
    public function verification_status_polling_does_not_use_up_the_link_click(): void
    {
        $user = User::factory()->approved()->unverified()->create();

        // Three minutes of the verify-email page's 10-second polling.
        foreach (range(1, 18) as $ignored) {
            $this->actingAs($user)->getJson('/email/verification-status')->assertOk();
        }

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);
        $this->actingAs($user)->get($url)->assertRedirect();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }
}
