<?php

namespace Tests\Unit\Location;

use App\Services\Location\PlaceImportRuns;
use Illuminate\Cache\Lock;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/**
 * MySQL and MariaDB report changed rows, not matched rows, so a DatabaseLock
 * refresh in the same second as the acquire updates nothing and returns false
 * although the lease is intact. That failed every production import at its
 * first heartbeat. These locks reproduce both outcomes without a database.
 */
class PlaceImportLeaseTest extends TestCase
{
    public function test_a_refresh_that_changes_nothing_keeps_an_owned_lease(): void
    {
        $lock = $this->lock(stillOwned: true);
        $runs = $this->holding($lock);

        $runs->heartbeat(null);
        $runs->heartbeat(null);

        $this->assertSame(2, $lock->refreshes);
    }

    public function test_a_lost_lease_still_fails_the_import(): void
    {
        $runs = $this->holding($this->lock(stillOwned: false));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The import lost its lock');

        $runs->heartbeat(null);
    }

    /** The import as it is inside exclusively(), holding the given lease. */
    private function holding(Lock $lock): PlaceImportRuns
    {
        $runs = app(PlaceImportRuns::class);
        (new ReflectionProperty(PlaceImportRuns::class, 'lock'))->setValue($runs, $lock);

        return $runs;
    }

    private function lock(bool $stillOwned): Lock
    {
        return new class('places:import', PlaceImportRuns::LEASE_SECONDS, $stillOwned) extends Lock
        {
            public int $refreshes = 0;

            public function __construct(string $name, int $seconds, private readonly bool $stillOwned)
            {
                parent::__construct($name, $seconds);
            }

            public function acquire(): bool
            {
                return true;
            }

            public function release(): bool
            {
                return true;
            }

            public function forceRelease(): void {}

            /**
             * Zero affected rows, as MySQL reports for an unchanged expiration.
             *
             * @param  int|null  $seconds
             */
            public function refresh($seconds = null): bool
            {
                $this->refreshes++;

                return false;
            }

            protected function getCurrentOwner(): ?string
            {
                return $this->stillOwned ? $this->owner : 'another-process';
            }
        };
    }
}
