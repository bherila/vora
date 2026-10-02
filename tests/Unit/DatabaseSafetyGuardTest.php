<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\SafeTestCase;
use Tests\TestCase;

/**
 * Guards the database-name allowlist in {@see SafeTestCase}.
 *
 * The MariaDB branch of the safety check originally demanded the database be
 * exactly `vora_ci`. Running the CI suite with `--parallel` made that too
 * strict: Laravel gives each process its own `vora_ci_test_<token>` database.
 * Widening an allowlist is the kind of change that quietly stops guarding
 * anything, so the accepted shape is pinned here rather than left to the
 * regular expression's author.
 */
class DatabaseSafetyGuardTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function approvedNames(): array
    {
        return [
            ['vora_ci'],
            ['vora_ci_test_1'],
            ['vora_ci_test_7'],
            ['vora_ci_test_42'],
        ];
    }

    /**
     * @return list<array{string}>
     */
    public static function rejectedNames(): array
    {
        return [
            'production database' => ['vora'],
            'different stem' => ['vora_ci2'],
            'prefixed' => ['xvora_ci'],
            'suffixed without the token shape' => ['vora_ci_backup'],
            'non-numeric token' => ['vora_ci_test_prod'],
            'empty token' => ['vora_ci_test_'],
            'token with trailing text' => ['vora_ci_test_1x'],
            'trailing newline' => ["vora_ci_test_1\n"],
            'trailing space' => ['vora_ci_test_1 '],
            'leading whitespace' => [' vora_ci'],
            'nested token' => ['vora_ci_test_1_test_2'],
            'empty' => [''],
        ];
    }

    #[DataProvider('approvedNames')]
    public function test_it_accepts_the_ci_database_and_its_parallel_derivatives(string $database): void
    {
        $this->assertTrue(
            $this->isApprovedCiDatabase($database),
            sprintf('Expected %s to be an approved CI database name.', var_export($database, true))
        );
    }

    #[DataProvider('rejectedNames')]
    public function test_it_rejects_anything_else(string $database): void
    {
        $this->assertFalse(
            $this->isApprovedCiDatabase($database),
            sprintf(
                'Expected %s to be rejected; the MariaDB branch of the safety check '.
                'must not admit names outside the CI job\'s own databases.',
                var_export($database, true)
            )
        );
    }
}
