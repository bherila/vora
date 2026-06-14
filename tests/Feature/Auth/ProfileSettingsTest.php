<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function authenticated_users_can_update_name_and_email(): void
    {
        Notification::fake();

        $user = User::factory()->approved()->create([
            'name' => 'Original Name',
            'display_name' => 'Original Display',
            'birth_date' => '1990-01-15',
            'email' => 'original@example.com',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->patchJson('/api/account', [
            'name' => 'Updated Name',
            'display_name' => 'Updated Display',
            'email' => 'updated@example.com',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertSame('Updated Display', $user->display_name);
        $this->assertSame('1990-01-15', $user->birth_date?->toDateString());
        $this->assertSame('updated@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    #[Test]
    public function users_cannot_change_name_when_name_is_locked(): void
    {
        $user = User::factory()->approved()->create([
            'name' => 'Original Name',
            'name_locked' => true,
        ]);

        $this->actingAs($user)->patchJson('/api/account', [
            'name' => 'Updated Name',
            'display_name' => $user->display_name,
            'email' => $user->email,
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Your real name is locked and cannot be changed.');
    }

    #[Test]
    public function users_can_change_display_name_when_real_name_is_locked(): void
    {
        $user = User::factory()->approved()->create([
            'name' => 'Original Name',
            'display_name' => 'Original Display',
            'name_locked' => true,
        ]);

        $this->actingAs($user)->patchJson('/api/account', [
            'name' => 'Original Name',
            'display_name' => 'Updated Display',
            'email' => $user->email,
        ])->assertOk()
            ->assertJsonPath('data.display_name', 'Updated Display');

        $user->refresh();
        $this->assertSame('Original Name', $user->name);
        $this->assertSame('Updated Display', $user->display_name);
    }

    #[Test]
    public function users_cannot_change_email_when_email_is_locked(): void
    {
        $user = User::factory()->approved()->create([
            'email' => 'original@example.com',
            'email_locked' => true,
        ]);

        $this->actingAs($user)->patchJson('/api/account', [
            'name' => $user->name,
            'display_name' => $user->display_name,
            'email' => 'updated@example.com',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Your email is locked and cannot be changed.');
    }

    #[Test]
    public function users_cannot_use_an_existing_email(): void
    {
        User::factory()->approved()->create(['email' => 'existing@example.com']);
        $user = User::factory()->approved()->create(['email' => 'original@example.com']);

        $this->actingAs($user)->patchJson('/api/account', [
            'name' => 'Updated Name',
            'display_name' => $user->display_name,
            'email' => 'existing@example.com',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function users_cannot_change_birth_date_from_account_settings(): void
    {
        $user = User::factory()->approved()->create([
            'birth_date' => '1990-01-15',
        ]);

        $this->actingAs($user)->patchJson('/api/account', [
            'name' => $user->name,
            'display_name' => $user->display_name,
            'birth_date' => '1989-01-15',
            'email' => $user->email,
        ])->assertOk();

        $this->assertSame('1990-01-15', $user->fresh()->birth_date?->toDateString());
    }
}
