<?php

namespace Tests;

/**
 * Base TestCase for all feature tests.
 *
 * Extends SafeTestCase, which restricts local tests to SQLite in-memory and
 * permits MariaDB only for its isolated CI service container.
 *
 * Usage:
 *   - Feature tests should extend this class
 *   - Use RefreshDatabase trait for tests that need a clean database
 *   - Use DatabaseTransactions trait for tests that should rollback changes
 *
 * Example:
 *   class MyFeatureTest extends TestCase
 *   {
 *       use RefreshDatabase;
 *
 *       public function test_something(): void
 *       {
 *           // Database is guaranteed to be an isolated test target
 *           $user = User::factory()->create();
 *           // ...
 *       }
 *   }
 */
abstract class TestCase extends SafeTestCase
{
    /**
     * Prepare each feature test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
