<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_password_changed_elsewhere_ends_this_session(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->get('/user/settings')->assertOk();

        // Another device changes the password (reset, admin, or settings there).
        $user->forceFill(['password' => Hash::make('another-password-123')])->save();

        $this->get('/user/settings')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function the_session_that_changes_the_password_stays_signed_in(): void
    {
        Mail::fake();
        $user = User::factory()->approved()->create();
        $rememberToken = $user->remember_token;

        $this->actingAs($user)->get('/user/settings')->assertOk();
        $this->postJson('/api/change-password', [
            'current_password' => 'password',
            'password' => 'a-new-password-123',
            'password_confirmation' => 'a-new-password-123',
        ])->assertOk();

        $this->get('/user/settings')->assertOk();
        $this->assertAuthenticatedAs($user);
        // Remember-me cookies issued under the old password stop working too.
        $this->assertNotSame($rememberToken, $user->fresh()->remember_token);
    }

    #[Test]
    public function saving_a_user_without_a_new_password_keeps_the_remember_token(): void
    {
        $user = User::factory()->approved()->create();
        $rememberToken = $user->remember_token;

        $user->forceFill(['name' => 'Renamed'])->save();

        $this->assertSame($rememberToken, $user->fresh()->remember_token);
    }

    #[Test]
    public function a_disabled_account_loses_its_session_outside_the_approved_area(): void
    {
        $user = User::factory()->approved()->create();
        $this->actingAs($user)->get('/user/settings')->assertOk();

        $user->forceFill(['is_disabled' => true])->save();

        $this->get('/user/settings')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function a_disabled_account_is_refused_on_account_json_endpoints(): void
    {
        $user = User::factory()->approved()->disabled()->create();

        $this->actingAs($user)->patchJson('/api/account', ['name' => 'Changed'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account has been disabled.');
        $this->assertGuest();
        $this->assertNotSame('Changed', $user->fresh()->name);
    }

    #[Test]
    public function a_disabled_account_can_still_log_out(): void
    {
        $user = User::factory()->approved()->disabled()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
