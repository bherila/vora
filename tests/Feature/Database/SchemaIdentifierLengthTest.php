<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests run on SQLite; production runs on MySQL. SQLite accepts identifiers of
 * any length, MySQL rejects anything over 64 characters, so an over-long index
 * name passes CI and then fails the production migration — which is what
 * happened to `recent_profile_visits`, whose generated unique-index name came
 * to 65 characters and aborted a deploy.
 *
 * Laravel derives index names from the table plus every indexed column, so wide
 * composite indexes on long table names overflow easily. Name those explicitly.
 */
class SchemaIdentifierLengthTest extends TestCase
{
    use RefreshDatabase;

    /** MySQL's hard limit on table, column, and index identifiers. */
    private const MYSQL_MAX_IDENTIFIER_LENGTH = 64;

    public function test_no_schema_identifier_exceeds_the_mysql_limit(): void
    {
        $offenders = [];

        foreach (DB::select("SELECT name, type, tbl_name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'") as $object) {
            if (strlen((string) $object->name) > self::MYSQL_MAX_IDENTIFIER_LENGTH) {
                $offenders[] = sprintf(
                    '%s "%s" on table "%s" is %d characters',
                    $object->type,
                    $object->name,
                    $object->tbl_name,
                    strlen((string) $object->name),
                );
            }
        }

        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $table) {
            foreach (DB::select('PRAGMA table_info('.$table->name.')') as $column) {
                if (strlen((string) $column->name) > self::MYSQL_MAX_IDENTIFIER_LENGTH) {
                    $offenders[] = sprintf('column "%s" on table "%s" is %d characters', $column->name, $table->name, strlen((string) $column->name));
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Identifiers longer than '.self::MYSQL_MAX_IDENTIFIER_LENGTH.' characters fail on MySQL but pass on SQLite:'],
            $offenders,
            ['', 'Pass an explicit short name as the second argument to unique()/index().'],
        )));
    }
}
