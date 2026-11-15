<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\RequireConfirmedPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasskeyPasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function both_passkey_enrollment_routes_require_a_confirmed_password(): void
    {
        foreach (['api/passkeys/register/options', 'api/passkeys/register'] as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($route): bool => $route->uri() === $uri && in_array('POST', $route->methods(), true));
            $this->assertNotNull($route, $uri);
            $this->assertContains(RequireConfirmedPassword::class, $route->gatherMiddleware(), $uri);
        }
    }

    #[Test]
    public function a_session_must_confirm_its_password_before_requesting_enrollment_options(): void
    {
        $user = User::factory()->approved()->create();

        // No Accept header, as the package's passkey client sends.
        $this->actingAs($user)->post('/api/passkeys/register/options')
            ->assertStatus(423)
            ->assertJsonPath('error', 'password_confirmation_required');

        $this->actingAs($user)->postJson('/api/auth/confirm-password', ['password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->actingAs($user)->postJson('/api/passkeys/register/options')->assertStatus(423);

        $this->actingAs($user)->postJson('/api/auth/confirm-password', ['password' => 'password'])
            ->assertOk()->assertJsonPath('success', true);
        $this->actingAs($user)->postJson('/api/passkeys/register/options')
            ->assertOk()->assertJsonStructure(['challenge']);
    }

    #[Test]
    public function a_confirmation_expires_with_the_password_timeout(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->postJson('/api/auth/confirm-password', ['password' => 'password'])->assertOk();
        $this->travel((int) config('auth.password_timeout') + 1)->seconds();

        $this->actingAs($user)->postJson('/api/passkeys/register/options')->assertStatus(423);
    }

    #[Test]
    public function password_confirmation_is_rate_limited(): void
    {
        $user = User::factory()->approved()->create();

        foreach (range(1, 6) as $ignored) {
            $this->actingAs($user)->postJson('/api/auth/confirm-password', ['password' => 'wrong'])->assertStatus(422);
        }

        $this->actingAs($user)->postJson('/api/auth/confirm-password', ['password' => 'password'])->assertStatus(429);
    }
}
