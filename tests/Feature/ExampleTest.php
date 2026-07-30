<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Example feature test demonstrating safe database usage.
 *
 * This test uses RefreshDatabase, which will run migrations on each test.
 * SafeTestCase permits only SQLite in-memory or an isolated CI SQL service, so
 * RefreshDatabase can never accidentally affect a shared database.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the application returns a successful response.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    /**
     * Test that we are using an approved isolated test database.
     *
     * This test verifies our safety mechanism is working.
     */
    public function test_database_is_an_approved_isolated_target(): void
    {
        if ($this->getDatabaseDriver() === 'sqlite') {
            $this->assertSame(':memory:', $this->getDatabaseName());

            return;
        }

        $driver = $this->getDatabaseDriver();
        $this->assertContains($driver, ['mysql', 'mariadb']);
        $this->assertSame('vora_ci', $this->getDatabaseName());
        $this->assertTrue(filter_var(env('CI', false), FILTER_VALIDATE_BOOL));
        $marker = $driver === 'mysql' ? 'VORA_MYSQL_CI' : 'VORA_MARIADB_CI';
        $this->assertTrue(filter_var(env($marker, false), FILTER_VALIDATE_BOOL));
    }

    /**
     * Test that database tables can be created via migrations.
     *
     * This confirms RefreshDatabase works with each approved backend.
     */
    public function test_migrations_create_expected_tables(): void
    {
        // These tables should exist after RefreshDatabase runs migrations
        $this->assertTrue(
            \Schema::hasTable('users'),
            'Users table should exist after migrations'
        );
        $this->assertTrue(
            \Schema::hasTable('sessions'),
            'Sessions table should exist after migrations'
        );
        $this->assertTrue(
            \Schema::hasTable('cache'),
            'Cache table should exist after migrations'
        );
        $this->assertTrue(
            \Schema::hasTable('jobs'),
            'Jobs table should exist after migrations'
        );
    }
}
