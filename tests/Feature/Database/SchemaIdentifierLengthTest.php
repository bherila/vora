<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SQLite accepts identifiers of any length while MySQL rejects anything over
 * 64 characters. An over-long index name therefore passed the default SQLite
 * suite and then failed a production migration. This guard now runs against
 * both the default SQLite schema and the CI-only MySQL schema.
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

        foreach ($this->schemaObjects() as $object) {
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

        foreach ($this->schemaColumns() as $column) {
            if (strlen((string) $column->name) > self::MYSQL_MAX_IDENTIFIER_LENGTH) {
                $offenders[] = sprintf('column "%s" on table "%s" is %d characters', $column->name, $column->tbl_name, strlen((string) $column->name));
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Identifiers longer than '.self::MYSQL_MAX_IDENTIFIER_LENGTH.' characters fail on MySQL but pass on SQLite:'],
            $offenders,
            ['', 'Pass an explicit short name as the second argument to unique()/index().'],
        )));
    }

    /**
     * @return list<object{name: string, type: string, tbl_name: string}>
     */
    private function schemaObjects(): array
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            return DB::select(
                <<<'SQL'
                    SELECT table_name AS name, 'table' AS type, table_name AS tbl_name
                    FROM information_schema.tables
                    WHERE table_schema = DATABASE()
                    UNION ALL
                    SELECT index_name AS name, 'index' AS type, table_name AS tbl_name
                    FROM information_schema.statistics
                    WHERE table_schema = DATABASE() AND index_name != 'PRIMARY'
                    SQL
            );
        }

        return DB::select("SELECT name, type, tbl_name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'");
    }

    /**
     * @return list<object{name: string, tbl_name: string}>
     */
    private function schemaColumns(): array
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            return DB::select(
                <<<'SQL'
                    SELECT column_name AS name, table_name AS tbl_name
                    FROM information_schema.columns
                    WHERE table_schema = DATABASE()
                    SQL
            );
        }

        $columns = [];
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $table) {
            foreach (DB::select('PRAGMA table_info('.$table->name.')') as $column) {
                $column->tbl_name = $table->name;
                $columns[] = $column;
            }
        }

        return $columns;
    }
}
