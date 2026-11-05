<?php

namespace Tests\Feature\Auth;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PasskeyIndexIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_existing_generic_hash_index_is_qualified_without_losing_uniqueness(): void
    {
        config(['bherila-auth.passkeys.table' => 'legacy_passkeys']);
        Schema::create('legacy_passkeys', static function (Blueprint $table): void {
            $table->string('credential_id_hash')->unique('passkey_credential_id_hash_unique');
        });
        DB::table('legacy_passkeys')->insert(['credential_id_hash' => 'existing-hash']);

        $migration = require database_path('migrations/2026_11_05_000000_qualify_passkey_hash_index_names.php');
        $migration->up();
        $this->assertTrue(Schema::hasIndex('legacy_passkeys', 'legacy_passkeys_credential_id_hash_unique', 'unique'));
        $this->assertFalse(Schema::hasIndex('legacy_passkeys', 'passkey_credential_id_hash_unique'));
        $this->assertDatabaseHas('legacy_passkeys', ['credential_id_hash' => 'existing-hash']);

        // Rolling the host back must not reclaim a name an embedded provider now owns.
        Schema::create('provider_passkeys', static function (Blueprint $table): void {
            $table->string('credential_id_hash')->unique('passkey_credential_id_hash_unique');
        });
        $migration->down();
        $this->assertTrue(Schema::hasIndex('legacy_passkeys', 'legacy_passkeys_credential_id_hash_unique', 'unique'));

        $this->expectException(QueryException::class);
        DB::table('legacy_passkeys')->insert(['credential_id_hash' => 'existing-hash']);
    }

    /** @return array<string, array{bool}> */
    public static function conflictingIndexes(): array
    {
        return ['wrong columns' => [true], 'not unique' => [false]];
    }

    #[DataProvider('conflictingIndexes')]
    public function test_a_conflicting_qualified_index_never_removes_the_original_unique_constraint(bool $wrongColumns): void
    {
        config(['bherila-auth.passkeys.table' => 'legacy_passkeys']);
        Schema::create('legacy_passkeys', static function (Blueprint $table) use ($wrongColumns): void {
            $table->string('credential_id_hash')->unique('passkey_credential_id_hash_unique');
            $table->string('other');
            if ($wrongColumns) {
                $table->unique('other', 'legacy_passkeys_credential_id_hash_unique');
            } else {
                $table->index('credential_id_hash', 'legacy_passkeys_credential_id_hash_unique');
            }
        });

        $migration = require database_path('migrations/2026_11_05_000000_qualify_passkey_hash_index_names.php');
        try {
            $migration->up();
            $this->fail('The conflicting qualified index should prevent migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unexpected definition', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasIndex('legacy_passkeys', 'passkey_credential_id_hash_unique', 'unique'));
    }
}
