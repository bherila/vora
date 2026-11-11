<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\EmergencyAccess;
use BWH\Auth\Mail\TwoFactorLoginMail;
use BWH\Auth\Models\TwoFactorAttempt;
use BWH\AuthManager\Embedded\EmbeddedContext;
use BWH\AuthManager\Models\User as ProviderUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\EmbeddedAuthTestCase;

class EmbeddedEmergencyAccessTest extends EmbeddedAuthTestCase
{
    public function test_mounted_provider_and_emergency_password_mfa_keep_users_attempts_routes_and_guards_separate(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        config(['auth.emergency_access.enabled' => true, 'auth.emergency_access.user_ids' => ['27']]);
        $host = User::factory()->admin()->create(['id' => 27, 'email' => 'recovery@example.test', 'password' => self::PASSWORD]);
        $providerAttempt = app(EmbeddedContext::class)->run(function (): TwoFactorAttempt {
            $provider = ProviderUser::factory()->create(['id' => 27, 'email' => 'recovery@example.test', 'password' => self::PASSWORD]);

            return TwoFactorAttempt::createForUser($provider);
        });
        $this->get('/emergency-login')->assertOk();
        $this->post('/emergency-login', ['email' => $host->email, 'password' => self::PASSWORD])->assertRedirect();
        $hostAttempt = TwoFactorAttempt::sole();
        $this->assertGuest('web');
        $this->assertGuest('auth-manager');
        Mail::assertSent(TwoFactorLoginMail::class, fn (TwoFactorLoginMail $mail): bool => $mail->confirmUrl === self::ORIGIN.'/emergency-login/confirm/'.$hostAttempt->token);
        // The mounted provider's attempt with the same numeric user ID is never a host proof.
        $pending = session()->get(EmergencyAccess::PENDING);
        $this->postJson('/emergency-login/verify', ['attempt_token' => $providerAttempt->token, 'code' => $providerAttempt->code])->assertUnprocessable();
        $this->withSession([EmergencyAccess::PENDING => $pending])
            ->postJson('/emergency-login/verify', ['attempt_token' => $hostAttempt->token, 'code' => $hostAttempt->code])->assertOk();
        $this->assertAuthenticatedAs($host, 'web');
        $this->assertGuest('auth-manager');
        $this->getJson('/api/admin/invites')->assertOk();
        $this->assertSame('bherila-auth.two-factor.', config('bherila-auth.two_factor.route_name_prefix', 'bherila-auth.two-factor.'));
        app(EmbeddedContext::class)->run(function () use ($providerAttempt): void {
            $this->assertFalse($providerAttempt->fresh()->is_used);
            $this->assertSame(1, TwoFactorAttempt::count());
        });
        $this->postJson('/logout')->assertOk();
        Http::assertNothingSent();
    }
}
