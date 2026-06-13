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
