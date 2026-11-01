<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ProviderLoginController;
use App\Http\Middleware\RequireProviderAccount;
use App\Models\User;
use App\Services\Auth\SignIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The seams between vora's auth modes (docs/auth-modes.md): which controller
 * serves each login route, and what sign-out does. The credential flows
 * themselves are covered by AuthFlowTest (local) and ProviderSignInTest
 * (external).
 */
class AuthModesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function modes(): array
    {
        return ['local' => ['local', 'local'], 'external' => ['provider', 'provider']];
    }

    #[DataProvider('modes')]
    public function test_the_configured_mode_boots(string $value, string $expected): void
    {
        config(['auth.sign_in' => $value]);

        $this->assertSame($expected, SignIn::mode());
        $this->assertSame($expected === 'provider', SignIn::usesProvider());
    }

    public function test_an_unrecognised_mode_falls_back_to_local(): void
    {
        config(['auth.sign_in' => 'embedded']);

        $this->assertSame('local', SignIn::mode());
    }

    #[DataProvider('modes')]
    public function test_login_routes_resolve_to_the_right_controllers(string $value, string $expected): void
    {
        config(['auth.sign_in' => $value]);

        $this->assertSame(LoginController::class.'@show', Route::getRoutes()->match(request()->create('/login'))->getActionName());
        $this->assertSame(ProviderLoginController::class.'@callback', Route::getRoutes()->match(request()->create('/oauth/callback'))->getActionName());
        $this->assertSame(ProviderLoginController::class.'@logout', Route::getRoutes()->match(request()->create('/logout', 'POST'))->getActionName());
        $this->assertSame(LoginController::class.'@store', Route::getRoutes()->match(request()->create('/login', 'POST'))->getActionName());
    }

    public function test_local_login_serves_the_form_and_not_the_provider_callback(): void
    {
        config(['auth.sign_in' => 'local']);

        $this->get('/login')->assertOk();
        $this->get('/oauth/callback?code=x&state=y')->assertNotFound();
    }

    public function test_external_login_redirects_to_the_provider_and_refuses_password_posts(): void
    {
        config([
            'auth.sign_in' => 'provider',
            'bherila-auth.oauth_client.base_url' => 'https://id.example.test',
            'bherila-auth.oauth_client.client_id' => 'client-1',
            'bherila-auth.oauth_client.client_secret' => 'secret-1',
            'bherila-auth.oauth_client.redirect_uri' => 'http://localhost/oauth/callback',
        ]);

        $location = (string) $this->get('/login')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://id.example.test/oauth/authorize?', $location);
        $this->post('/login', ['email' => 'a@example.test', 'password' => 'secret'])->assertNotFound();
    }

    public function test_local_logout_ends_only_the_local_session(): void
    {
        config(['auth.sign_in' => 'local', 'bherila-auth.oauth_client.base_url' => 'https://id.example.test']);
        Http::fake();

        $this->actingAs(User::factory()->approved()->create())->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_external_logout_hands_the_provider_session_to_the_provider(): void
    {
        config([
            'auth.sign_in' => 'provider',
            'bherila-auth.oauth_client.provider' => 'example',
            'bherila-auth.oauth_client.base_url' => 'https://id.example.test',
            'bherila-auth.oauth_client.client_id' => 'client-1',
            'bherila-auth.oauth_client.client_secret' => 'secret-1',
        ]);
        $user = User::factory()->approved()->create(['oauth_provider' => 'example', 'oauth_subject' => '42']);

        $response = $this->actingAs($user)
            ->withSession([RequireProviderAccount::SESSION_KEY => ['example', '42']])
            ->postJson('/logout')->assertOk();

        $this->assertGuest();
        $this->assertStringStartsWith('https://id.example.test/oauth/end-session?', (string) $response->json('redirect'));
    }

    public function test_embedded_mode_is_covered_once_auth_manager_ships_it(): void
    {
        $this->markTestSkipped('Embedded mode (auth-manager mounted under /auth) waits on bherila/auth-manager#104; see docs/auth-modes.md.');
    }
}
