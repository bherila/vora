<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        // First created user has id 1 (always admin); make a non-id-1 admin actor
        // so user-id-1 protections are exercised separately.
        User::factory()->admin()->create(); // id 1

        return User::factory()->admin()->create(); // id 2, the actor
    }

    #[Test]
    public function test_non_admin_cannot_reach_admin_pages_or_api(): void
    {
        User::factory()->admin()->create(); // occupy id 1 (always-admin)
        $user = User::factory()->approved()->create(); // a genuine non-admin

        $this->actingAs($user)->get('/admin/users')->assertForbidden();
        $this->actingAs($user)->getJson('/api/admin/users')->assertForbidden();
    }

    #[Test]
    public function test_pending_or_disabled_admin_cannot_reach_admin_api(): void
    {
        User::factory()->admin()->create(); // occupy id 1 (always-admin)

        // is_admin is set but the account is not through the access model yet.
        $pendingAdmin = User::factory()->admin()->state(['approved_at' => null])->create();
        $disabledAdmin = User::factory()->admin()->disabled()->create();

        $this->actingAs($pendingAdmin)->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($disabledAdmin)->getJson('/api/admin/users')->assertForbidden();
    }

    #[Test]
    public function test_admin_can_list_users(): void
    {
        $admin = $this->admin();
        User::factory()->count(2)->create();

        $this->actingAs($admin)->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(4, 'data'); // id1 admin + actor + 2
    }

    #[Test]
    public function test_admin_can_approve_a_pending_user(): void
    {
        $admin = $this->admin();
        $pending = User::factory()->pendingApproval()->create();

        $this->actingAs($admin)->postJson("/api/admin/users/{$pending->id}/approve")->assertOk();

        $pending->refresh();
        $this->assertNotNull($pending->approved_at);
        $this->assertSame($admin->id, $pending->approved_by_user_id);
    }

    #[Test]
    public function test_admin_cannot_approve_an_unverified_user(): void
    {
        $admin = $this->admin();
        $pending = User::factory()->pendingApproval()->unverified()->create();

        $this->actingAs($admin)->postJson("/api/admin/users/{$pending->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Users must verify their email before they can be approved.');

        $pending->refresh();
        $this->assertNull($pending->approved_at);
        $this->assertNull($pending->email_verified_at);
    }

    #[Test]
    public function test_admin_can_toggle_admin_and_disabled_flags(): void
    {
        $admin = $this->admin();
        $target = User::factory()->approved()->create();

        $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}", ['is_admin' => true])->assertOk();
        $this->assertTrue($target->fresh()->is_admin);

        $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}", ['is_disabled' => true])->assertOk();
        $this->assertTrue($target->fresh()->is_disabled);
    }

    #[Test]
    public function admin_can_toggle_identity_and_field_lock_flags(): void
    {
        $admin = $this->admin();
        $target = User::factory()->approved()->create();

        $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}", ['id_verified' => true])->assertOk();
        $this->assertNotNull($target->fresh()->id_verified_at);

        $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}", ['name_locked' => true])->assertOk();
        $this->assertTrue($target->fresh()->name_locked);

        $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}", ['email_locked' => true])->assertOk();
        $this->assertTrue($target->fresh()->email_locked);
    }

    #[Test]
    public function test_admin_cannot_disable_self_or_primary_admin(): void
    {
        $admin = $this->admin(); // id 2

        $this->actingAs($admin)->patchJson("/api/admin/users/{$admin->id}", ['is_disabled' => true])->assertForbidden();
        $this->actingAs($admin)->patchJson('/api/admin/users/1', ['is_disabled' => true])->assertForbidden();
    }

    #[Test]
    public function test_admin_cannot_delete_self_or_primary_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$admin->id}")->assertForbidden();
        $this->actingAs($admin)->deleteJson('/api/admin/users/1')->assertForbidden();
    }

    #[Test]
    public function test_admin_can_delete_another_user(): void
    {
        $admin = $this->admin();
        $target = User::factory()->approved()->create();

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$target->id}")->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }
}
