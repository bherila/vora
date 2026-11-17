<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSessionTimezoneTest extends TestCase
{
    public function test_application_timezone_is_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_mysql_family_connections_pin_their_session_to_utc(): void
    {
        foreach (['mysql', 'mariadb'] as $name) {
            $this->assertSame('+00:00', config("database.connections.{$name}.timezone"),
                "The {$name} session must use UTC instead of inheriting the server's SYSTEM timezone.");
        }
    }

    public function test_mariadb_session_interprets_app_timestamps_as_utc(): void
    {
        if (DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('The isolated MariaDB CI service runs this engine-level regression.');
        }

        $this->assertSame('+00:00', DB::scalar('SELECT @@session.time_zone'));
        $this->assertSame(0, (int) DB::scalar('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())'));

        // London skips this wall time at spring-forward; it is a valid UTC instant.
        $this->assertSame(1806197400, (int) DB::scalar("SELECT UNIX_TIMESTAMP('2027-03-28 01:30:00')"));
        $this->assertSame(1815654000, (int) DB::scalar("SELECT UNIX_TIMESTAMP('2027-07-15 12:20:00')"));
    }
}
