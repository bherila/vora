<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SafeTestCase - A base test class that enforces isolated test database usage.
 *
 * Local tests are restricted to SQLite in-memory. The CI-only SQL job may use
 * its MariaDB loopback service container with dedicated database credentials.
 *
 * The phpunit.xml file sets DB_CONNECTION=sqlite and DB_DATABASE=:memory:,
 * but this class adds runtime verification as an additional safety layer.
 *
 * Usage:
 *   - Feature tests should extend Tests\TestCase (which extends this class)
 *   - Use the RefreshDatabase trait freely; this guard runs before it
 */
abstract class SafeTestCase extends BaseTestCase
{
    private const SQL_CI_DATABASE = 'vora_ci';

    private const SQL_CI_USERNAME = 'vora_ci';

    /**
     * Boot the testing helper traits and verify database safety.
     */
    protected function setUpTraits(): array
    {
        $this->assertDatabaseIsSafe();

        return parent::setUpTraits();
    }

    /**
     * Assert that the database connection is an approved isolated test target.
     *
     * MariaDB is deliberately asymmetric with the local default: it is
     * permitted only in CI, only with its explicit workflow marker, and only
     * against a loopback service container's fixed database and username. This
     * prevents environment credentials from silently redirecting a destructive
     * test.
     *
     * @throws RuntimeException if the configured database is not safe for tests
     */
    protected function assertDatabaseIsSafe(): void
    {
        $connection = DB::connection();
        $driverName = $connection->getDriverName();
        $database = $connection->getDatabaseName();

        if ($driverName === 'sqlite' && $database === ':memory:') {
            return;
        }

        $host = (string) $connection->getConfig('host');
        $username = (string) $connection->getConfig('username');
        $isCi = filter_var(env('CI', false), FILTER_VALIDATE_BOOL);
        $isMariaDbCi = filter_var(env('VORA_MARIADB_CI', false), FILTER_VALIDATE_BOOL);
        $isLoopback = in_array($host, ['127.0.0.1', 'localhost'], true);
        $isMariaDbCiTarget = $driverName === 'mariadb' && $isMariaDbCi;

        if (
            $isMariaDbCiTarget
            && $isCi
            && $isLoopback
            && $database === self::SQL_CI_DATABASE
            && $username === self::SQL_CI_USERNAME
        ) {
            return;
        }

        if ($driverName === 'sqlite') {
            throw new RuntimeException(
                "SAFETY ERROR: Local tests must use in-memory SQLite, but database is '{$database}'.\n\n".
                "Using a file-based database could persist test data unexpectedly.\n".
                "Ensure phpunit.xml contains:\n".
                '  <env name="DB_DATABASE" value=":memory:"/>'
            );
        }

        throw new RuntimeException(
            "SAFETY ERROR: '{$driverName}' tests are not targeting an isolated CI SQL service.\n\n".
            "Local tests must use SQLite in-memory. MariaDB tests require CI=true,\n".
            'VORA_MARIADB_CI=true, host 127.0.0.1 or localhost, database '.
            self::SQL_CI_DATABASE.', and username '.self::SQL_CI_USERNAME.".\n".
            'Shared and production databases must never be used for tests.'
        );
    }

    /**
     * Get the current database driver name for assertions in tests.
     */
    protected function getDatabaseDriver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * Get the current database name for assertions in tests.
     */
    protected function getDatabaseName(): string
    {
        return DB::connection()->getDatabaseName();
    }
}
