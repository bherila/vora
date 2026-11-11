<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\RequireProviderAccount;
use App\Models\User;
use App\Services\Auth\EmergencyAccess;
use BWH\Auth\Mail\TwoFactorLoginMail;
use BWH\Auth\Models\TwoFactorAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmergencyAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        config(['auth.sign_in' => 'provider', 'auth.emergency_access.enabled' => true]);
    }

    private function administrator(): User
    {
        $user = User::factory()->admin()->create(['id' => 27, 'email' => 'recovery@example.test', 'password' => Hash::make('synthetic-password')]);
        config(['auth.emergency_access.user_ids' => [(string) $user->id]]);

        return $user;
    }

    private function challenge(User $user): TwoFactorAttempt
    {
        $this->post('/emergency-login', ['email' => $user->email, 'password' => 'synthetic-password', 'remember' => true])
            ->assertRedirect();
        $this->assertGuest();

        return TwoFactorAttempt::latest('id')->firstOrFail();
    }

    private function finish(TwoFactorAttempt $attempt): void
    {
        $this->postJson('/emergency-login/verify', ['attempt_token' => $attempt->token, 'code' => $attempt->code])
            ->assertOk()->assertJsonPath('success', true)->assertCookieMissing(Auth::guard('web')->getRecallerName());
    }

    public function test_explicit_password_and_email_mfa_reach_admin_during_provider_outage_and_logout_locally(): void
    {
        $user = $this->administrator();
        $rememberBefore = $user->remember_token;
        config(['bherila-auth.provider_identity.enabled' => true]);
        $this->get('/emergency-login')->assertOk()->assertSee('Emergency administrator access')->assertSee('name="_token"', false);
        $attempt = $this->challenge($user);
        $this->assertFalse(session()->has(EmergencyAccess::SESSION));
        Mail::assertSent(TwoFactorLoginMail::class, function (TwoFactorLoginMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, '/emergency-login/confirm/') && ! str_contains($html, '/auth/auth/two-factor/');
        });
        $this->get('/emergency-login/two-factor/'.$attempt->token)->assertOk()->assertSee('Email verification code');
        $this->finish($attempt);
        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/admin/invites')->assertOk();
        $this->assertSame($rememberBefore, $user->fresh()->remember_token);
        $this->postJson('/logout')->assertOk();
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_feature_is_default_off_and_invalid_designations_fail_closed(): void
    {
        $this->administrator();
        foreach ([false, true] as $enabled) {
            config(['auth.emergency_access.enabled' => $enabled, 'auth.emergency_access.user_ids' => $enabled ? ['27', 'recovery@example.test'] : ['27']]);
            $this->get('/emergency-login')->assertNotFound();
            $this->postJson('/emergency-login', ['email' => 'recovery@example.test', 'password' => 'synthetic-password'])->assertNotFound();
        }
        config(['auth.emergency_access.user_ids' => ['27'], 'auth.sign_in' => 'local']);
        $this->get('/emergency-login')->assertNotFound();
    }

    public function test_bad_password_and_ordinary_or_bound_admin_never_receive_a_challenge(): void
    {
        $user = $this->administrator();
        $this->postJson('/emergency-login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        foreach ([['is_admin' => false], ['is_admin' => true, 'oauth_provider' => 'identity', 'oauth_subject' => '42'], ['oauth_provider' => null, 'oauth_subject' => '42'], ['oauth_provider' => '', 'oauth_subject' => null]] as $attributes) {
            $user->forceFill($attributes)->save();
            $this->postJson('/emergency-login', ['email' => $user->email, 'password' => 'synthetic-password'])->assertUnprocessable();
            $this->assertGuest();
        }
        $this->assertDatabaseCount('auth_two_factor_attempts', 0);
        Mail::assertNothingSent();
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function eligibilityChanges(): array
    {
        return [
            'demoted' => [['is_admin' => false]],
            'unapproved' => [['approved_at' => null]],
            'unverified' => [['email_verified_at' => null]],
            'disabled' => [['is_disabled' => true]],
            'banned' => [['banned_at' => '2026-11-01 00:00:00']],
            'deactivated' => [['deactivated_at' => '2026-11-01 00:00:00']],
            'deleted' => [['deleted_at' => '2026-11-01 00:00:00']],
            'bound' => [['oauth_provider' => 'identity', 'oauth_subject' => '42']],
            'partial binding' => [['oauth_subject' => '42']],
            'password changed' => [['password' => 'different-password']],
        ];
    }

    #[DataProvider('eligibilityChanges')]
    public function test_pending_mfa_rechecks_fresh_identity_and_credentials(array $changes): void
    {
        $user = $this->administrator();
        $attempt = $this->challenge($user);
        $user->forceFill($changes)->save();
        $this->postJson('/emergency-login/verify', ['attempt_token' => $attempt->token, 'code' => $attempt->code])->assertUnprocessable();
        $this->assertGuest();
        $this->assertFalse(session()->has(EmergencyAccess::SESSION));
        Http::assertNothingSent();
    }

    #[DataProvider('eligibilityChanges')]
    public function test_emergency_session_rechecks_fresh_identity_and_credentials(array $changes): void
    {
        $user = $this->administrator();
        $this->finish($this->challenge($user));
        $user->forceFill($changes)->save();
        $this->getJson('/api/admin/invites')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_disable_or_remove_designation_blocks_pending_and_existing_sessions(): void
    {
        $user = $this->administrator();
        $attempt = $this->challenge($user);
        config(['auth.emergency_access.user_ids' => []]);
        $this->postJson('/emergency-login/verify', ['attempt_token' => $attempt->token, 'code' => $attempt->code])->assertNotFound();
        config(['auth.emergency_access.user_ids' => ['27']]);
        $this->finish($this->challenge($user));
        config(['auth.emergency_access.enabled' => false]);
        $this->getJson('/api/admin/invites')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_existing_generic_local_or_remember_restored_session_does_not_gain_emergency_access(): void
    {
        $user = $this->administrator();
        $this->actingAs($user)->getJson('/api/admin/invites')->assertUnauthorized();
        $this->assertGuest();
        $user->setRememberToken('synthetic-remember-token');
        $user->save();
        $guard = Auth::guard('web');
        $cookie = $guard->getRecallerName();
        $value = implode('|', [$user->id, $user->remember_token, $user->password]);
        $guard->forgetUser();
        $this->withCookie($cookie, $value)->getJson('/api/admin/invites')->assertUnauthorized();
        $this->assertGuest();

        // Even a retained proof cannot turn recaller restoration into a fresh emergency session.
        $user->setRememberToken('second-synthetic-token');
        $user->save();
        $value = implode('|', [$user->id, $user->remember_token, $user->password]);
        $guard->forgetUser();
        $this->withSession([EmergencyAccess::SESSION => app(EmergencyAccess::class)->proof($user)])
            ->withCookie($cookie, $value)->getJson('/api/admin/invites')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_mfa_token_cannot_be_used_without_the_emergency_password_session_or_for_another_account(): void
    {
        $user = $this->administrator();
        $attempt = $this->challenge($user);
        $this->withSession([EmergencyAccess::PENDING => null])->postJson('/emergency-login/verify', ['attempt_token' => $attempt->token, 'code' => $attempt->code])->assertUnprocessable();
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'synthetic-password'])->assertNotFound();
        $this->assertFalse(session()->has(RequireProviderAccount::SESSION_KEY));
    }

    public function test_wrong_code_resend_and_confirmation_use_shared_mfa_without_bypassing_it(): void
    {
        $user = $this->administrator();
        $attempt = $this->challenge($user);
        $wrong = $attempt->code === '000000' ? '111111' : '000000';
        $this->postJson('/emergency-login/verify', ['attempt_token' => $attempt->token, 'code' => $wrong])->assertUnprocessable();
        $this->assertGuest();
        $resend = $this->postJson('/emergency-login/resend', ['attempt_token' => $attempt->token])->assertOk();
        $new = TwoFactorAttempt::where('token', $resend->json('attempt_token'))->firstOrFail();
        $this->get('/emergency-login/confirm/'.$new->token)->assertOk();
        $this->assertGuest();
        $this->post('/emergency-login/confirm/'.$new->token)->assertRedirect();
        $this->getJson('/api/admin/invites')->assertOk();
    }

    public function test_emergency_password_post_requires_csrf_and_uses_existing_audit_lockout(): void
    {
        $user = $this->administrator();
        $this->get('/emergency-login')->assertOk();
        $this->app['env'] = 'production';
        try {
            $this->post('/emergency-login', ['email' => $user->email, 'password' => 'synthetic-password'])->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
        config(['bherila-auth.throttle.max_attempts' => 1]);
        $this->postJson('/emergency-login', ['email' => $user->email, 'password' => 'wrong-password'])->assertUnprocessable();
        $this->postJson('/emergency-login', ['email' => $user->email, 'password' => 'synthetic-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('auth_two_factor_attempts', 0);
        $this->assertDatabaseHas('auth_audit_log', ['event' => 'login_failed']);
        Mail::assertNothingSent();
    }

    public function test_native_html_code_submission_redirects_on_failure_and_success(): void
    {
        $user = $this->administrator();
        $this->get('/emergency-login')->assertOk();
        $this->app['env'] = 'production';
        try {
            $this->post('/emergency-login', ['_token' => session()->token(), 'email' => $user->email, 'password' => 'synthetic-password'])->assertRedirect();
            $attempt = TwoFactorAttempt::sole();
            $wrong = $attempt->code === '000000' ? '111111' : '000000';
            $this->post('/emergency-login/verify', ['_token' => session()->token(), 'attempt_token' => $attempt->token, 'code' => $wrong])
                ->assertRedirect('/emergency-login')->assertSessionHasErrors('code');
            $this->assertGuest();
            $this->post('/emergency-login/verify', ['_token' => session()->token(), 'attempt_token' => $attempt->token, 'code' => $attempt->code])->assertRedirect('/');
            $this->getJson('/api/admin/invites')->assertOk();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_suspicious_email_report_works_without_password_session_and_never_authenticates(): void
    {
        $user = $this->administrator();
        $attempt = $this->challenge($user);
        session()->invalidate();
        $this->assertFalse(session()->has(EmergencyAccess::PENDING));
        $this->get('/emergency-login/report/'.$attempt->token)->assertOk();
        $this->assertFalse($attempt->fresh()->is_used);
        $this->assertFalse($attempt->fresh()->is_suspicious);
        $this->assertGuest();
        $this->post('/emergency-login/report/'.$attempt->token)->assertOk()->assertDontSee('/forgot-password');
        $this->assertTrue($attempt->fresh()->is_used);
        $this->assertTrue($attempt->fresh()->is_suspicious);
        $this->assertGuest();
        $this->assertFalse(session()->has(EmergencyAccess::SESSION));
    }

    public function test_signed_in_provider_user_visiting_emergency_entry_keeps_their_session(): void
    {
        $this->administrator();
        $bound = User::factory()->approved()->create(['oauth_provider' => 'identity', 'oauth_subject' => '42']);
        $this->actingAs($bound)->withSession([RequireProviderAccount::SESSION_KEY => ['identity', '42']]);
        $this->get('/emergency-login')->assertRedirect();
        $this->assertAuthenticatedAs($bound);
        $this->assertFalse(session()->has(EmergencyAccess::SESSION));
    }

    public function test_guest_redirect_cannot_mint_emergency_proof_for_pre_authenticated_or_remember_restored_user(): void
    {
        $user = $this->administrator();
        $attempt = $this->challenge($user);
        // Exercise the emergency middleware's own boundary even if another
        // middleware admitted a pre-existing authentication in this request.
        $this->withoutMiddleware(RequireProviderAccount::class);
        $this->actingAs($user);
        $this->post('/emergency-login/verify', ['attempt_token' => $attempt->token, 'code' => 'wrong'])->assertRedirect();
        $this->get('/emergency-login/confirm/'.$attempt->token)->assertRedirect();
        $this->post('/emergency-login/confirm/'.$attempt->token)->assertRedirect();
        $this->assertFalse(session()->has(EmergencyAccess::SESSION));
        $this->assertFalse($attempt->fresh()->is_used);
        $this->assertAuthenticatedAs($user);

        $user->setRememberToken('synthetic-recaller-token');
        $user->save();
        $guard = Auth::guard('web');
        $guard->forgetUser();
        session()->forget($guard->getName());
        $this->withCookie($guard->getRecallerName(), implode('|', [$user->id, $user->remember_token, $user->password]))
            ->post('/emergency-login/confirm/'.$attempt->token)->assertRedirect();
        $this->assertFalse(session()->has(EmergencyAccess::SESSION));
        $this->assertFalse($attempt->fresh()->is_used);
    }
}
