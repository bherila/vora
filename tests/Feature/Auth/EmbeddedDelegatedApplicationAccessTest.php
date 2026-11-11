<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use BWH\Auth\OAuth\DelegatedAccess\ApplicationAccessAdapter;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedAccessException;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedRefusal;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedRequestContext;
use BWH\Auth\Testing\AssertsDelegatedAccessAdapter;
use BWH\AuthManager\Embedded\EmbeddedContext;
use BWH\AuthManager\Models\User as ProviderUser;
use Illuminate\Support\Facades\DB;
use Tests\Support\EmbeddedAuthTestCase;

class EmbeddedDelegatedApplicationAccessTest extends EmbeddedAuthTestCase
{
    use AssertsDelegatedAccessAdapter;

    protected function delegatedAccessManager(): string
    {
        return 'manager';
    }

    protected function delegatedAccessTruth(string $subject): array
    {
        return ['application_admin' => User::where('oauth_provider', 'example')->where('oauth_subject', $subject)->sole()->isAdmin(), 'workspaces' => []];
    }

    public function test_the_host_adapter_neither_inherits_provider_roles_nor_operates_in_the_provider_context(): void
    {
        config(['bherila-auth.oauth_client.provider' => 'example',
            'bherila-auth.oauth_client.base_url' => 'https://application.example.test/auth']);
        $manager = User::factory()->admin()->create(['oauth_provider' => 'example', 'oauth_subject' => 'manager']);
        $provider = app(EmbeddedContext::class)->run(fn () => ProviderUser::factory()->create(['user_role' => 'admin']));
        $hostBefore = (array) DB::table('users')->find($manager->id);
        $providerBefore = (array) DB::table('auth_manager_users')->find($provider->id);

        // Pinned shared 0.28.6 requires a bare issuer. A real mounted issuer
        // currently fails closed; never replace /auth with a different issuer.
        $this->delegatedAccessEndpoint('manager', ['operation' => 'capabilities'])->assertStatus(503)
            ->assertJsonPath('error', 'invalid_verifier_configuration');
        $this->assertTrue($this->delegatedAccessCall('manager', ['operation' => 'capabilities'])['controls']['application_admin']);
        $this->assertDelegatedActorRefusedEverywhere((string) $provider->id, 'manager');
        $this->postJson('/auth/application-access')->assertNotFound();

        app()->instance(DelegatedRequestContext::class, new DelegatedRequestContext(
            'https://identity.example.test', 'manager', 'application', 'synthetic-nonce', 'capabilities',
        ));
        try {
            app(EmbeddedContext::class)->run(fn () => app(ApplicationAccessAdapter::class)->handle('manager', ['operation' => 'capabilities']));
            $this->fail('The host adapter must refuse an active provider context.');
        } catch (DelegatedAccessException $refusal) {
            $this->assertSame(DelegatedRefusal::NOT_AUTHORIZED, $refusal->outcome);
        } finally {
            app()->forgetInstance(DelegatedRequestContext::class);
        }
        $this->assertSame($hostBefore, (array) DB::table('users')->find($manager->id));
        $this->assertSame($providerBefore, (array) DB::table('auth_manager_users')->find($provider->id));
        $this->assertDatabaseCount('auth_audit_log', 0);
        $this->assertDatabaseCount('auth_manager_audit_log', 0);
        $this->assertSame('web', auth()->getDefaultDriver());
    }
}
