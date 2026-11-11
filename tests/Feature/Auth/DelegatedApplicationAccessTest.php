<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use BWH\Auth\Models\AuthAuditLog;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedContract;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedRefusal;
use BWH\Auth\Testing\AssertsDelegatedAccessAdapter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DelegatedApplicationAccessTest extends TestCase
{
    use AssertsDelegatedAccessAdapter, RefreshDatabase;

    private User $manager;

    private User $target;

    private string $conformanceManager = 'ManagerCase';

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.sign_in' => 'provider', 'bherila-auth.oauth_client.provider' => 'example',
            'bherila-auth.oauth_client.base_url' => 'https://identity.example.test',
            'bherila-auth.delegated_access.issuer' => 'https://identity.example.test',
            'bherila-auth.delegated_access.application' => 'example-app']);
        User::factory()->admin()->create(['id' => 1, 'oauth_provider' => 'example', 'oauth_subject' => 'Primary']);
        $this->manager = User::factory()->admin()->create(['oauth_provider' => 'example', 'oauth_subject' => 'ManagerCase', 'display_name' => 'Example manager']);
        $this->target = User::factory()->approved()->create(['oauth_provider' => 'example', 'oauth_subject' => 'TargetCase', 'display_name' => 'Example target']);
    }

    protected function delegatedAccessManager(): string
    {
        return $this->conformanceManager;
    }

    protected function delegatedAccessTruth(string $subject): array
    {
        $user = User::where('oauth_provider', 'example')->where('oauth_subject', $subject)->sole();

        return ['application_admin' => $user->isAdmin(), 'workspaces' => []];
    }

    public function test_account_only_adapter_passes_shared_conformance_with_real_local_accounts(): void
    {
        $this->assertTrue($this->delegatedAccessAccountOnly());
        $this->assertFalse($this->delegatedAccessCapabilities()['controls']['provisioning']);
        $this->assertDelegatedActorRefusedEverywhere('TargetCase', 'Primary');
        $this->assertDelegatedProtectedMembershipsHold('ManagerCase', 'TargetCase');
        $this->assertDelegatedApplicationAdminFollowsAllowedEdits('ManagerCase', 'ManagerCase');
        $this->assertDelegatedApplicationAdminFollowsAllowedEdits('ManagerCase', 'Primary');
        $this->assertDelegatedApplicationAdminFollowsAllowedEdits('ManagerCase', 'TargetCase');
        $this->assertDelegatedStaleRevisionRefused('ManagerCase', 'TargetCase');
        $this->assertDelegatedUnadvertisedRoleRefused('ManagerCase', 'TargetCase');
        $this->assertDelegatedUpdateKeepsUnseenMemberships('ManagerCase', 'TargetCase');
        User::factory()->approved()->create(['oauth_provider' => 'foreign', 'oauth_subject' => 'Hidden', 'display_name' => 'Hidden foreign account']);
        $this->assertDelegatedSearchStaysInScope('ManagerCase', 'subjects', 'target', 'hidden');
        $this->assertDelegatedSearchStaysInScope('ManagerCase', 'workspaces', 'anything', 'nothing');
        $this->assertDelegatedMetadataIsWellFormed('ManagerCase', 'TargetCase');
        $this->assertDelegatedRemoveRefusedWithoutPartialChange('ManagerCase', 'ManagerCase');
        $this->assertDelegatedRemoveRefusedWithoutPartialChange('ManagerCase', 'Primary');
        $this->assertDelegatedRemoveStripsOnlyTheManagedProjection('ManagerCase', 'TargetCase');
    }

    public function test_signed_endpoint_uses_the_shared_receipts_to_replay_one_audited_write(): void
    {
        $this->assertDelegatedReceiptsReplayThroughTheEndpoint('ManagerCase', 'TargetCase');
        $this->assertSame(1, AuthAuditLog::where('event', 'delegated_application_access_update')->count());
        $metadata = AuthAuditLog::sole()->metadata;
        $this->assertSame('ManagerCase', $metadata['actor_subject']);
        $this->assertSame('TargetCase', $metadata['subject']);
        $this->assertNotEmpty($metadata['jti']);
        $this->assertTrue(DelegatedContract::validOperationId($metadata['operation_id']));
    }

    public function test_removal_clears_only_administration_and_preserves_independent_account_state(): void
    {
        $this->target->forceFill(['is_admin' => true, 'approved_at' => null, 'is_disabled' => true,
            'banned_at' => now(), 'deactivated_at' => now(), 'legal_hold_at' => now(),
            'library_enabled' => true, 'trusted_inviter' => true])->save();
        $before = $this->target->fresh()->getRawOriginal();
        $state = $this->delegatedAccessRead('ManagerCase', 'TargetCase');
        $this->assertStringContainsString('sign-in access', $state['access']['removal_notice']);
        $this->assertDelegatedRemoveStripsOnlyTheManagedProjection('ManagerCase', 'TargetCase');
        $after = $this->target->fresh()->getRawOriginal();
        unset($before['is_admin'], $before['updated_at'], $after['is_admin'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertNull($this->target->fresh()->deleted_at);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function ineligibleActors(): array
    {
        return ['ordinary' => [['is_admin' => false]], 'disabled' => [['is_disabled' => true]],
            'pending' => [['approved_at' => null]], 'unverified' => [['email_verified_at' => null]],
            'banned' => [['banned_at' => '2026-11-01 00:00:00']], 'deactivated' => [['deactivated_at' => '2026-11-01 00:00:00']],
            'deleted' => [['deleted_at' => '2026-11-01 00:00:00']]];
    }

    #[DataProvider('ineligibleActors')]
    public function test_ineligible_administrators_are_refused_all_operations(array $attributes): void
    {
        $this->manager->forceFill($attributes)->save();
        $this->conformanceManager = 'Primary';
        $this->assertDelegatedActorRefusedEverywhere('ManagerCase', 'TargetCase');
        $this->assertDatabaseCount('auth_audit_log', 0);
    }

    public function test_bindings_are_exact_and_never_resolved_by_email_host_id_or_provider_role(): void
    {
        User::factory()->admin()->create(['email' => 'unbound-admin@example.test', 'oauth_provider' => null, 'oauth_subject' => null]);
        User::factory()->admin()->create(['oauth_provider' => 'foreign', 'oauth_subject' => 'ForeignAdmin']);
        User::factory()->admin()->create(['oauth_provider' => 'Example', 'oauth_subject' => 'WrongProviderCase']);
        foreach (['managercase', 'unbound-admin@example.test', (string) $this->manager->id, 'ForeignAdmin', 'WrongProviderCase', 'ProviderAdministrator'] as $actor) {
            $this->assertDelegatedActorRefusedEverywhere($actor, 'TargetCase');
        }
        foreach (['targetcase', $this->target->email, (string) $this->target->id, 'ForeignAdmin'] as $subject) {
            $state = $this->delegatedAccessCall('ManagerCase', ['operation' => 'read', 'subject' => $subject]);
            $this->assertFalse($state['provisioned']);
            $this->assertFalse($state['allowed_edits']['provision']);
        }
        $this->assertDelegatedRefusal([DelegatedRefusal::NOT_PROVISIONED], 'ManagerCase', [
            'operation' => 'update', 'subject' => 'Unknown', 'display_name' => 'Example newcomer', 'expected_revision' => null,
            'access' => ['application_admin' => true, 'workspaces' => []], 'operation_id' => DelegatedContract::operationId(),
        ], 'unsupported provisioning');
    }

    public function test_the_last_eligible_administrator_is_not_offered_self_demotion_or_removal(): void
    {
        User::whereKey(1)->update(['is_disabled' => true]);
        $state = $this->delegatedAccessRead('ManagerCase', 'ManagerCase');
        $this->assertFalse($state['allowed_edits']['application_admin']);
        $this->assertFalse($state['allowed_edits']['remove']);
        $this->assertDelegatedApplicationAdminFollowsAllowedEdits('ManagerCase', 'ManagerCase');
        $this->assertDelegatedRemoveRefusedWithoutPartialChange('ManagerCase', 'ManagerCase');
    }

    public function test_a_no_op_cannot_rewrite_the_primary_administrators_raw_flag(): void
    {
        User::whereKey(1)->update(['is_admin' => false]);
        $state = $this->delegatedAccessRead('ManagerCase', 'Primary');
        $this->assertTrue($state['access']['application_admin']);
        $payload = $this->updatePayload($state['revision']);
        $payload['subject'] = 'Primary';
        $this->delegatedAccessCall('ManagerCase', $payload);
        $this->assertFalse(User::findOrFail(1)->is_admin);
        $this->assertDelegatedRemoveRefusedWithoutPartialChange('ManagerCase', 'Primary');
    }

    public function test_revisions_cover_actor_reach_and_target_labels_but_not_login_observations(): void
    {
        $state = $this->delegatedAccessRead('ManagerCase', 'TargetCase');
        $this->target->forceFill(['last_login_at' => now()])->save();
        $this->assertSame($state['revision'], $this->delegatedAccessRead('ManagerCase', 'TargetCase')['revision']);
        $this->target->forceFill(['display_name' => 'Changed account label'])->save();
        $this->staleUpdate($state['revision']);
        $state = $this->delegatedAccessRead('ManagerCase', 'TargetCase');
        $this->manager->forceFill(['email' => 'changed-manager@example.test'])->save();
        $this->staleUpdate($state['revision']);
        $this->assertDatabaseCount('auth_audit_log', 0);
    }

    public function test_authority_is_rechecked_after_resolution_before_the_locked_update(): void
    {
        $state = $this->delegatedAccessRead('ManagerCase', 'TargetCase');
        $revoked = false;
        DB::listen(function (QueryExecuted $query) use (&$revoked): void {
            if (! $revoked && str_contains($query->sql, 'oauth_subject') && in_array('ManagerCase', $query->bindings, true)) {
                $revoked = true;
                DB::table('users')->where('id', $this->manager->id)->update(['is_admin' => false]);
            }
        });
        $this->assertDelegatedRefusal([DelegatedRefusal::NOT_AUTHORIZED], 'ManagerCase', $this->updatePayload($state['revision']), 'revoked in-flight actor');
        $this->assertTrue($revoked);
        $this->assertFalse($this->target->fresh()->is_admin);
        $this->assertDatabaseCount('auth_audit_log', 0);
    }

    public function test_an_audit_failure_rolls_back_the_privilege_change(): void
    {
        $state = $this->delegatedAccessRead('ManagerCase', 'TargetCase');
        AuthAuditLog::creating(static fn () => throw new RuntimeException('Synthetic audit failure'));
        try {
            $this->delegatedAccessCall('ManagerCase', $this->updatePayload($state['revision']));
            $this->fail('The audit failure must propagate.');
        } catch (RuntimeException $failure) {
            $this->assertSame('Synthetic audit failure', $failure->getMessage());
        } finally {
            AuthAuditLog::flushEventListeners();
        }
        $this->assertFalse($this->target->fresh()->is_admin);
        $this->assertDatabaseCount('auth_audit_log', 0);
    }

    public function test_the_default_off_endpoint_has_no_cookie_or_bearer_authentication_fallback(): void
    {
        $this->postJson('/application-access', [])->assertNotFound();
        config(['bherila-auth.delegated_access.enabled' => true]);
        $this->actingAs($this->manager)->withToken('not-an-actor-assertion')->postJson('/application-access', [])->assertStatus(503);
        $this->assertDatabaseCount('auth_audit_log', 0);
    }

    public function test_a_silently_cancelled_audit_rolls_back_the_privilege_change(): void
    {
        $state = $this->delegatedAccessRead('ManagerCase', 'TargetCase');
        AuthAuditLog::creating(static fn (): bool => false);
        try {
            $this->delegatedAccessCall('ManagerCase', $this->updatePayload($state['revision']));
            $this->fail('A cancelled audit must abort the mutation.');
        } catch (RuntimeException $failure) {
            $this->assertSame('Delegated access audit was not persisted.', $failure->getMessage());
        } finally {
            AuthAuditLog::flushEventListeners();
        }
        $this->assertFalse($this->target->fresh()->is_admin);
        $this->assertDatabaseCount('auth_audit_log', 0);
    }

    public function test_a_silently_cancelled_role_save_cannot_report_or_audit_a_success(): void
    {
        $state = $this->delegatedAccessRead('ManagerCase', 'TargetCase');
        User::updating(static fn (): bool => false);
        try {
            $this->delegatedAccessCall('ManagerCase', $this->updatePayload($state['revision']));
            $this->fail('A cancelled role save must abort the mutation.');
        } catch (RuntimeException $failure) {
            $this->assertSame('Delegated access update was not persisted.', $failure->getMessage());
        } finally {
            User::flushEventListeners();
        }
        $this->assertFalse($this->target->fresh()->is_admin);
        $this->assertDatabaseCount('auth_audit_log', 0);
    }

    private function staleUpdate(string $revision): void
    {
        $this->assertDelegatedRefusal([DelegatedRefusal::REVISION_CONFLICT], 'ManagerCase', $this->updatePayload($revision), 'stale policy or label');
    }

    /** @return array<string, mixed> */
    private function updatePayload(string $revision): array
    {
        return ['operation' => 'update', 'subject' => 'TargetCase', 'expected_revision' => $revision,
            'access' => ['application_admin' => true, 'workspaces' => []], 'operation_id' => DelegatedContract::operationId()];
    }
}
