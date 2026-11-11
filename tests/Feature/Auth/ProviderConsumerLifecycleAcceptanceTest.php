<?php

namespace Tests\Feature\Auth;

use App\Models\RecentProfileVisit;
use App\Models\User;
use BWH\Auth\OAuth\Lifecycle\IdentityTombstone;
use BWH\Auth\OAuth\Lifecycle\IdentityTombstoneClient;
use BWH\Auth\OAuth\Lifecycle\IdentityTombstoneHandler;
use BWH\AuthManager\Embedded\EmbeddedContext;
use BWH\AuthManager\Models\IdentityTombstone as ProviderTombstone;
use BWH\AuthManager\Models\User as ProviderUser;
use BWH\AuthManager\Services\OAuthClientGrantService;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProviderConsumerAcceptanceTestCase;

class ProviderConsumerLifecycleAcceptanceTest extends ProviderConsumerAcceptanceTestCase
{
    public function test_real_provider_login_and_status_drive_vora(): void
    {
        $this->signInConsumer();
        $this->consumerRequest('GET', '/api/account/storage')->assertOk()->assertSessionHas('bherila_auth.provider_session.generation', 0);
        $this->travel(300)->seconds();
        $this->consumerRequest('GET', '/api/account/storage')->assertOk()->assertSessionHas('bherila_auth.provider_session.generation', 0);
        $this->assertSame(1, $this->statusCalls);
    }

    public function test_the_real_reconciliation_route_refuses_invalid_client_credentials(): void
    {
        $url = self::ORIGIN.'/auth/api/reconciliation/identity-status';
        $clientId = (string) config('bherila-auth.oauth_client.client_id');
        $subject = ['subject' => (string) $this->provider->getKey()];
        $refused = Http::withBasicAuth($clientId, 'incorrect synthetic secret')->post($url, $subject);
        $this->assertSame(401, $refused->status());
        $this->assertSame('no-store, private', $refused->header('Cache-Control'));
        $accepted = Http::withBasicAuth($clientId, (string) config('bherila-auth.oauth_client.client_secret'))->post($url, $subject);
        $this->assertSame(200, $accepted->status());
        $this->assertTrue($accepted->json('active'));
        $this->assertSame(0, $accepted->json('credential_version'));
    }

    public static function mutations(): array
    {
        return [['password'], ['reset'], ['disable'], ['delete']];
    }

    #[DataProvider('mutations')]
    public function test_real_provider_mutations_expire_the_existing_vora_session_at_exactly_300_seconds(string $mutation): void
    {
        $this->signInConsumer();
        $checkedAt = session('bherila_auth.provider_session.checked_at');
        $this->mutateProvider($mutation);
        $this->travel(299)->seconds();
        $this->consumerRequest('GET', '/api/account/storage')->assertOk()
            ->assertSessionHas('bherila_auth.provider_session.generation', 0)
            ->assertSessionHas('bherila_auth.provider_session.checked_at', $checkedAt);
        $this->assertSame(0, $this->statusCalls);
        $this->travel(1)->seconds();
        $this->consumerRequest('GET', '/api/account/storage')->assertUnauthorized()->assertSessionMissing('bherila_auth.provider_session');
        $this->assertGuest('web');
        $this->assertSame(1, $this->statusCalls);
        // Ending a login is not itself the application's deletion cascade.
        $this->assertDatabaseHas('users', ['id' => $this->consumer->id, 'deleted_at' => null]);
    }

    public function test_admin_writes_read_current_provider_status_inside_the_ordinary_freshness_window(): void
    {
        $this->signInConsumer();
        $this->mutateProvider('disable');
        $this->consumerRequest('GET', '/api/account/storage')->assertOk();
        $this->assertSame(0, $this->statusCalls);
        $this->consumerRequest('PUT', '/api/admin/app-icon/short-name', ['short_name' => 'Example'])->assertUnauthorized();
        $this->assertGuest('web');
        $this->assertSame(1, $this->statusCalls);
    }

    public function test_provider_outage_preserves_retryable_state_without_extending_freshness_or_granting_admin_work(): void
    {
        $this->signInConsumer();
        $baseline = session('bherila_auth.provider_session');
        $this->providerUnavailable = true;
        $this->travel(299)->seconds();
        $this->consumerRequest('GET', '/api/account/storage')->assertOk()->assertSessionHas('bherila_auth.provider_session', $baseline);
        $this->assertSame(0, $this->statusCalls);
        $this->consumerRequest('PUT', '/api/admin/app-icon/short-name', ['short_name' => 'Example'])
            ->assertStatus(503)->assertHeader('Retry-After', '30')->assertSessionHas('bherila_auth.provider_session', $baseline);
        $this->assertAuthenticatedAs($this->consumer, 'web');
        $this->travel(1)->seconds();
        $this->consumerRequest('GET', '/api/account/storage')->assertStatus(503)->assertHeader('Retry-After', '30')
            ->assertSessionHas('bherila_auth.provider_session', $baseline);
        $this->assertAuthenticatedAs($this->consumer, 'web');
        $this->providerUnavailable = false;
        $this->consumerRequest('GET', '/api/account/storage')->assertOk()
            ->assertSessionHas('bherila_auth.provider_session.generation', 0)
            ->assertSessionHas('bherila_auth.provider_session.checked_at', $baseline['checked_at'] + 300);
        $this->assertSame(3, $this->statusCalls);
    }

    public function test_real_tombstones_apply_vora_policy_before_provider_acknowledgement_and_do_not_hide_later_work(): void
    {
        Sleep::fake();
        $clientId = (string) config('bherila-auth.oauth_client.client_id');
        $additional = app(EmbeddedContext::class)->run(function () use ($clientId): array {
            $users = [];
            foreach (['held@example.test', 'cascade@example.test'] as $email) {
                $user = ProviderUser::factory()->create(['email' => $email, 'user_role' => 'user']);
                app(OAuthClientGrantService::class)->grant((string) $user->getKey(), $clientId);
                $users[] = $user;
            }

            return $users;
        });
        [$heldProvider, $cascadeProvider] = $additional;
        $held = User::factory()->approved()->create([
            'oauth_provider' => $this->providerNamespace, 'oauth_subject' => (string) $heldProvider->id, 'legal_hold_at' => now(),
        ]);
        $cascade = User::factory()->approved()->create([
            'oauth_provider' => $this->providerNamespace, 'oauth_subject' => (string) $cascadeProvider->id,
        ]);
        foreach ([$held, $cascade] as $viewer) {
            RecentProfileVisit::create(['viewer_user_id' => $viewer->id, 'target_type' => RecentProfileVisit::TARGET_USER,
                'target_id' => $this->consumer->id, 'visited_at' => now()]);
        }
        $chatVersion = $cascade->chat_sync_version;
        $this->providerAdministrator();
        foreach ([$this->provider, $heldProvider, $cascadeProvider] as $provider) {
            $this->providerRequest('DELETE', '/auth/api/admin/users/'.$provider->id, [])->assertAccepted();
        }
        $this->artisan('bherila-auth:consume-identity-tombstones')->assertFailed();
        $this->assertNotSoftDeleted($this->consumer); // Primary administrator needs an operator.
        $this->assertNotSoftDeleted($held);
        $this->assertSoftDeleted($cascade);
        $this->assertDatabaseMissing('recent_profile_visits', ['viewer_user_id' => $cascade->id]);
        $this->assertDatabaseHas('recent_profile_visits', ['viewer_user_id' => $held->id]);
        $this->assertSame($chatVersion + 1, $cascade->fresh()->chat_sync_version);
        $this->assertSame([['subject' => (string) $cascadeProvider->id, 'transaction_level' => 0,
            'deleted_at' => DB::table('users')->where('id', $cascade->id)->value('deleted_at')]], $this->acknowledgements);
        $this->assertNotNull($this->acknowledgements[0]['deleted_at']);
        $this->assertDatabaseCount('bherila_auth_identity_tombstone_retries', 2);
        [$tombstone, $acknowledgedAt] = app(EmbeddedContext::class)->run(function () use ($cascadeProvider): array {
            $record = ProviderTombstone::where('subject', $cascadeProvider->id)->sole();
            $this->assertSame(2, DB::table('identity_tombstone_clients')->whereNull('acknowledged_at')->count());

            return [new IdentityTombstone($record->public_id, $this->providerNamespace, (string) $record->subject,
                new DateTimeImmutable($record->tombstoned_at->toISOString()), new DateTimeImmutable($record->purge_after->toISOString()), null),
                $record->clients()->sole()->acknowledged_at->toISOString()];
        });
        // Duplicate delivery reuses actual provider data and the real consumer handler.
        app(IdentityTombstoneHandler::class)->handle($tombstone);
        $this->travel(1)->minute();
        app(IdentityTombstoneClient::class)->acknowledge($tombstone);
        app(EmbeddedContext::class)->run(function () use ($tombstone, $acknowledgedAt): void {
            $record = ProviderTombstone::where('public_id', $tombstone->id)->sole();
            $this->assertSame($acknowledgedAt, $record->clients()->sole()->acknowledged_at->toISOString());
            $this->assertSame(2, DB::table('identity_tombstone_clients')->whereNull('acknowledged_at')->count());
        });
        $this->assertSame(0, DB::connection($this->hostDatabase)->transactionLevel());
    }
}
