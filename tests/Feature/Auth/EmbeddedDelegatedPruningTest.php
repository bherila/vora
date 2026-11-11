<?php

namespace Tests\Feature\Auth;

use BWH\Auth\OAuth\DelegatedAccess\DatabaseReceiptStore;
use BWH\Auth\OAuth\DelegatedAccess\NonceStore;
use BWH\AuthManager\Embedded\EmbeddedContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\EmbeddedAuthTestCase;

class EmbeddedDelegatedPruningTest extends EmbeddedAuthTestCase
{
    public function test_daily_cleanup_remains_enabled_and_prunes_only_host_receipts(): void
    {
        config(['bherila-auth.delegated_access.enabled' => false, 'bherila-auth.delegated_access.writes_enabled' => false]);
        $event = collect(app(Schedule::class)->events())->sole(fn ($event): bool => is_string($event->command) && str_contains($event->command, 'bherila-auth:prune-delegated-nonces'));
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertTrue($event->filtersPass($this->app));
        $host = DB::getDefaultConnection();
        $expired = $this->receipt('a', time() - 40 * 86400);
        $retained = $this->receipt('b', time());
        DB::table(DatabaseReceiptStore::TABLE)->insert([$expired, $retained]);

        // A provider-side canary in the same isolated PDO must not be pruned by
        // the host scheduler, even if its contents have expired too.
        app(EmbeddedContext::class)->run(function () use ($expired): void {
            $migration = require database_path('migrations/2026_11_11_000001_create_delegated_access_receipts.php');
            $migration->up();
            DB::table(DatabaseReceiptStore::TABLE)->insert($expired);
        });
        // DatabaseNonceStore intentionally rejects :memory: as non-durable.
        // Exercise the real receipt cleanup without weakening that guard or
        // claiming this fixture tests durable nonce deletion.
        app()->instance(NonceStore::class, new class implements NonceStore
        {
            public function consume(string $key, int $seconds): bool
            {
                throw new LogicException('The pruning command must not consume assertions.');
            }
        });
        $this->artisan('bherila-auth:prune-delegated-nonces')
            ->expectsOutput('Pruned 1 delegated access receipt(s) older than 30 days.')->assertSuccessful();

        $this->assertSame([$retained['operation_key']], DB::table(DatabaseReceiptStore::TABLE)->pluck('operation_key')->all());
        app(EmbeddedContext::class)->run(fn () => $this->assertSame([$expired['operation_key']], DB::table(DatabaseReceiptStore::TABLE)->pluck('operation_key')->all()));
        $this->assertSame($host, DB::getDefaultConnection());
        $this->assertFalse(app(EmbeddedContext::class)->active());
    }

    /** @return array<string, mixed> */
    private function receipt(string $marker, int $created): array
    {
        return ['application' => 'example-app', 'operation_key' => str_repeat($marker, 64),
            'operation_id' => str_repeat($marker, 32), 'actor' => str_repeat('c', 64), 'request_hash' => str_repeat('d', 64),
            'status' => 200, 'response' => '{}', 'claimed_at' => $created, 'created_at' => $created];
    }
}
