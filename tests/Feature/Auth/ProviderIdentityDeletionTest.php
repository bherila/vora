<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use BWH\Auth\OAuth\Lifecycle\IdentityTombstone;
use BWH\Auth\OAuth\Lifecycle\IdentityTombstoneHandler;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ProviderIdentityDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const FEED = 'https://id.example.test/api/reconciliation/identity-tombstones';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'auth.sign_in' => 'provider',
            'bherila-auth.oauth_client.provider' => 'example',
            'bherila-auth.oauth_client.base_url' => 'https://id.example.test',
            'bherila-auth.oauth_client.client_id' => 'client-1',
            'bherila-auth.oauth_client.client_secret' => 'secret-1',
        ]);
        Sleep::fake();
        Http::preventStrayRequests();
    }

    /** @return array<string, mixed> */
    private static function tombstone(string $id, string $subject): array
    {
        return ['id' => $id, 'subject' => $subject, 'tombstoned_at' => '2026-10-27T12:00:00Z', 'purge_after' => '2026-11-26T12:00:00Z', 'provider_purged_at' => null];
    }

    public function test_people_deleted_at_the_provider_are_deleted_here_unless_an_administrator_must_decide(): void
    {
        $primary = User::factory()->admin()->create(['id' => 1, 'oauth_provider' => 'example', 'oauth_subject' => '1']);
        $guest = User::factory()->approved()->create(['oauth_provider' => 'example', 'oauth_subject' => '42']);
        $held = User::factory()->approved()->create(['oauth_provider' => 'example', 'oauth_subject' => '43', 'legal_hold_at' => now()]);
        // Subjects are case-sensitive and never matched across providers.
        $elsewhere = User::factory()->approved()->create(['oauth_provider' => 'other', 'oauth_subject' => '44']);

        $ids = ['aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-000000000002', 'aaaaaaaa-0000-4000-8000-000000000003', 'aaaaaaaa-0000-4000-8000-000000000004'];
        Http::fake([
            self::FEED.'?*' => Http::response(['contract_version' => 1, 'data' => [
                self::tombstone($ids[0], '42'),
                self::tombstone($ids[1], '43'),
                self::tombstone($ids[2], '44'),
                self::tombstone($ids[3], '1'),
            ], 'has_more' => false, 'next_cursor' => null]),
            self::FEED.'/*/acknowledgement' => fn (Request $request) => Http::response(['contract_version' => 1, 'acknowledgement' => [
                'tombstone_id' => explode('/', $request->url())[6], 'acknowledged_at' => '2026-10-27T12:05:00Z',
            ]]),
        ]);

        // The held and primary accounts wait for an administrator, so the run reports failure.
        $this->artisan('bherila-auth:consume-identity-tombstones')->assertFailed();

        $this->assertSoftDeleted($guest);
        $this->assertNotSoftDeleted($held);
        $this->assertNotSoftDeleted($elsewhere);
        $this->assertNotSoftDeleted($primary);
        $acknowledged = Http::recorded()
            ->filter(fn (array $exchange): bool => $exchange[0]->method() === 'PUT')
            ->map(fn (array $exchange): string => explode('/', $exchange[0]->url())[6])
            ->values()->all();
        // Deleted here, and nobody here to delete: done. The other two come back.
        $this->assertSame([$ids[0], $ids[2]], $acknowledged);
        $this->assertDatabaseCount('bherila_auth_identity_tombstone_retries', 2);

        // A repeat delivery of a deletion already applied is done.
        app(IdentityTombstoneHandler::class)->handle(new IdentityTombstone($ids[0], 'example', '42', new DateTimeImmutable, new DateTimeImmutable, null));
        $this->assertSoftDeleted($guest);
    }

    public function test_the_feed_is_read_only_under_provider_sign_in(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->sole(fn (Event $event): bool => str_contains((string) $event->command, 'bherila-auth:consume-identity-tombstones'));
        $this->assertSame('*/5 * * * *', $event->expression);

        $this->assertTrue($event->filtersPass($this->app));
        config(['auth.sign_in' => 'local']);
        $this->assertFalse($event->filtersPass($this->app));
    }
}
