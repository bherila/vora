<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function authenticated_users_can_update_name_and_email(): void
    {
        $user = User::factory()->approved()->create([
            'name' => 'Original Name',
            'email' => 'original@example.com',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->patchJson('/api/account', [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertSame('updated@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
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
            'email' => $user->email,
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Your name is locked and cannot be changed.');
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
            'email' => 'existing@example.com',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }
}
