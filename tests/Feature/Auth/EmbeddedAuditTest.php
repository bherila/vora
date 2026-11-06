<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\RequireProviderAccount;
use App\Models\User;
use App\Services\Auth\SignIn;
use BWH\Auth\Models\AuthAuditLog;
use BWH\AuthManager\Embedded\EmbeddedContext;
use Illuminate\Support\Facades\Route;
use Tests\Support\EmbeddedAuthTestCase;

class EmbeddedAuditTest extends EmbeddedAuthTestCase
{
    public function test_host_administrators_can_read_only_host_audit_records_when_credential_routes_are_disabled(): void
    {
        [$provider] = $this->grantedClient();
        $administrator = User::factory()->approved()->admin()->create([
            'oauth_provider' => SignIn::provider(), 'oauth_subject' => (string) $provider->getKey(),
        ]);
        AuthAuditLog::create([
            'user_id' => $administrator->getKey(), 'event' => 'host_audit_event', 'succeeded' => true,
        ]);
        app(EmbeddedContext::class)->run(fn () => AuthAuditLog::create([
            'user_id' => $provider->getKey(), 'event' => 'provider_audit_event', 'succeeded' => true,
        ]));

        $this->assertFalse(config('bherila-auth.routes.enabled'));
        $this->assertTrue(config('bherila-auth.audit.routes_enabled'));
        $this->actingAs($administrator, 'web')->withSession([
            RequireProviderAccount::SESSION_KEY => [$administrator->oauth_provider, $administrator->oauth_subject],
        ]);

        $this->get('/admin/audit-log')->assertOk();
        $this->getJson('/api/auth/audit-log/all')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'host_audit_event')
            ->assertJsonMissing(['event' => 'provider_audit_event']);
    }

    public function test_a_provider_session_cannot_read_the_host_audit_log(): void
    {
        [$provider] = $this->grantedClient();
        $page = $this->get('/auth/login')->assertOk();
        $session = $page->getCookie('auth_manager_session');
        $this->assertNotNull($session);
        $signedIn = $this->freshRequest()->withCookie('auth_manager_session', (string) $session->getValue())
            ->post('/auth/login', ['email' => $provider->email, 'password' => self::PASSWORD])
            ->assertRedirect(self::ORIGIN.'/auth');
        $session = $signedIn->getCookie('auth_manager_session');
        $this->assertNotNull($session);

        $this->freshRequest()->withCookie('auth_manager_session', (string) $session->getValue())
            ->getJson('/api/auth/audit-log/all')->assertUnauthorized();
        $this->assertGuest('web');
        $this->getJson('/auth/api/auth/audit-log/all')->assertNotFound();
    }

    public function test_host_credential_routes_stay_off_and_provider_route_names_remain_mounted(): void
    {
        $this->getJson('/api/passkeys')->assertNotFound();
        $this->postJson('/api/passkeys/auth/options')->assertNotFound();
        $this->postJson('/api/auth/two-factor/verify')->assertNotFound();
        $this->postJson('/api/auth/forgot-password')->assertNotFound();
        $this->postJson('/api/change-password')->assertNotFound();

        $this->assertSame('api/auth/audit-log/all', Route::getRoutes()->getByName('bherila-auth.audit.all')?->uri());
        $this->assertSame('auth/api/auth/two-factor/confirm/{token}', Route::getRoutes()->getByName('auth-manager.two-factor.confirm')?->uri());
        $this->assertSame('/login', route('login', absolute: false));
        $this->assertSame('/auth/login', route('auth-manager.login', absolute: false));
    }
}
