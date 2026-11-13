<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use BWH\Auth\Models\AuthAuditLog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminRoleChangeTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        User::factory()->admin()->create(['id' => 1]);

        return User::factory()->admin()->create();
    }

    public function test_self_and_primary_role_changes_refuse_the_entire_account_update(): void
    {
        $actor = $this->administrator();
        foreach ([User::findOrFail(1), $actor] as $target) {
            $this->actingAs($actor)->patchJson("/api/admin/users/{$target->id}", [
                'is_admin' => false, 'storage_unlimited' => true,
            ])->assertForbidden();
            $this->assertTrue($target->fresh()->isAdmin());
            $this->assertFalse($target->fresh()->storage_unlimited);
        }
        $this->assertDatabaseCount('auth_audit_log', 0);
    }

    public function test_local_role_changes_are_audited_even_when_optional_auth_logging_is_off(): void
    {
        $actor = $this->administrator();
        $target = User::factory()->approved()->create();
        config(['bherila-auth.audit.enabled' => false]);
        foreach ([true, false] as $admin) {
            $this->actingAs($actor)->patchJson("/api/admin/users/{$target->id}", ['is_admin' => $admin])
                ->assertOk()->assertJsonPath('data.is_admin', $admin);
            $audit = AuthAuditLog::latest('id')->firstOrFail();
            $this->assertSame('application_admin_changed', $audit->event);
            $this->assertSame($actor->id, $audit->acting_user_id);
            $this->assertSame($target->id, $audit->user_id);
            $this->assertTrue($audit->succeeded);
            $this->assertSame(['before' => ['application_admin' => ! $admin], 'after' => ['application_admin' => $admin]], $audit->metadata);
        }
        $this->assertDatabaseCount('auth_audit_log', 2);
    }

    public function test_a_no_op_keeps_other_settings_working_without_a_false_role_audit(): void
    {
        $actor = $this->administrator();
        $target = User::factory()->approved()->create();
        $this->actingAs($actor)->patchJson("/api/admin/users/{$target->id}", ['is_admin' => false, 'storage_unlimited' => true])->assertOk();
        $this->assertFalse($target->fresh()->isAdmin());
        $this->assertTrue($target->fresh()->storage_unlimited);
        $this->assertDatabaseCount('auth_audit_log', 0);
    }

    #[DataProvider('auditFailures')]
    public function test_an_audit_failure_rolls_back_the_role_and_other_account_changes(bool $throws): void
    {
        $actor = $this->administrator();
        $target = User::factory()->approved()->create();
        AuthAuditLog::creating(static function () use ($throws): bool {
            if ($throws) {
                throw new RuntimeException('Synthetic audit failure');
            }

            return false;
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($actor)->patchJson("/api/admin/users/{$target->id}", ['is_admin' => true, 'storage_unlimited' => true]);
            $this->fail('An unaudited role change must not commit.');
        } catch (RuntimeException $failure) {
            $this->assertSame($throws ? 'Synthetic audit failure' : 'Application administrator audit was not persisted.', $failure->getMessage());
        } finally {
            AuthAuditLog::flushEventListeners();
        }
        $this->assertFalse($target->fresh()->isAdmin());
        $this->assertFalse($target->fresh()->storage_unlimited);
        $this->assertDatabaseCount('auth_audit_log', 0);
    }

    public static function auditFailures(): array
    {
        return ['exception' => [true], 'canceled save' => [false]];
    }

    public function test_a_silently_canceled_account_save_cannot_report_or_audit_a_role_change(): void
    {
        $actor = $this->administrator();
        $target = User::factory()->approved()->create();
        User::updating(static fn (): bool => false);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($actor)->patchJson("/api/admin/users/{$target->id}", ['is_admin' => true]);
            $this->fail('A canceled account update must not succeed.');
        } catch (RuntimeException $failure) {
            $this->assertSame('Account update was not persisted.', $failure->getMessage());
        } finally {
            User::flushEventListeners();
        }
        $this->assertFalse($target->fresh()->isAdmin());
        $this->assertDatabaseCount('auth_audit_log', 0);
    }

    public function test_authority_is_rechecked_inside_the_locked_role_transaction(): void
    {
        $actor = $this->administrator();
        $target = User::factory()->approved()->create();
        $revoked = false;
        DB::listen(function (QueryExecuted $query) use ($actor, $target, &$revoked): void {
            if (! $revoked && str_contains($query->sql, 'users') && in_array((string) $target->id, array_map(strval(...), $query->bindings), true)) {
                $revoked = true;
                DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]);
            }
        });
        $this->actingAs($actor)->patchJson("/api/admin/users/{$target->id}", ['is_admin' => true])->assertForbidden();
        $this->assertTrue($revoked);
        $this->assertFalse($target->fresh()->isAdmin());
        $this->assertDatabaseCount('auth_audit_log', 0);
    }
}
