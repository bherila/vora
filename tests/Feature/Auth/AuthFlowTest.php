<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use BWH\Auth\Models\TwoFactorAttempt;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    // ─── Registration ───────────────────────────────────────

    #[Test]
    public function test_guest_can_register_and_first_user_becomes_approved_admin(): void
    {
        Event::fake([Registered::class]);

        $response = $this->post('/register', [
            'name' => 'First User',
            'email' => 'first@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ]);

        $response->assertRedirect(route('verification.notice'));
        Event::assertDispatched(Registered::class);

        $user = User::firstWhere('email', 'first@example.com');
        $this->assertNotNull($user);
        $this->assertTrue($user->isAdmin());
        $this->assertNotNull($user->approved_at);
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function test_subsequent_registration_is_non_admin_and_pending(): void
    {
        User::factory()->admin()->create();

        $this->post('/register', [
            'name' => 'Second User',
            'email' => 'second@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ])->assertRedirect(route('verification.notice'));

        $user = User::firstWhere('email', 'second@example.com');
        $this->assertFalse($user->isAdmin());
        $this->assertNull($user->approved_at);
        $this->assertTrue($user->isPendingApproval());
    }

    // ─── Login + two-factor ─────────────────────────────────

    #[Test]
    public function test_valid_password_starts_two_factor_challenge_without_logging_in(): void
    {
        $user = User::factory()->approved()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirectContains('/login/two-factor/');
        $this->assertGuest();
        $this->assertDatabaseHas('auth_two_factor_attempts', ['user_id' => $user->id]);
    }

    #[Test]
    public function test_completing_two_factor_logs_the_user_in(): void
    {
        $user = User::factory()->approved()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $attempt = TwoFactorAttempt::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->postJson('/api/auth/two-factor/verify', [
            'attempt_token' => $attempt->token,
            'code' => '999999', // allowed test code outside production
        ])->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function test_invalid_password_is_rejected(): void
    {
        $user = User::factory()->approved()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function test_disabled_account_cannot_log_in(): void
    {
        $user = User::factory()->approved()->disabled()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // ─── Approval / verification gate ───────────────────────

    #[Test]
    public function test_pending_user_is_redirected_from_app_area(): void
    {
        $user = User::factory()->pendingApproval()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('approval.pending'));
    }

    #[Test]
    public function test_unverified_user_is_redirected_to_verification(): void
    {
        $user = User::factory()->approved()->unverified()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
    }

    #[Test]
    public function test_approved_verified_user_can_access_app_area(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    #[Test]
    public function test_disabled_user_is_logged_out_at_the_gate(): void
    {
        $user = User::factory()->approved()->disabled()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function test_email_verification_link_marks_user_verified(): void
    {
        $user = User::factory()->approved()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('approval.pending'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    #[Test]
    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
