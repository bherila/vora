<?php

namespace Tests\Feature\Auth;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TwoFactorLinkSecretMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_migration_uses_the_configured_host_table_and_preserves_existing_attempts(): void
    {
        Schema::create('synthetic_host_attempts', static function (Blueprint $table): void {
            $table->id();
            $table->string('token');
        });
        DB::table('synthetic_host_attempts')->insert(['token' => 'synthetic-existing-attempt']);
        config(['bherila-auth.two_factor.table' => 'synthetic_host_attempts']);
        $migration = require database_path('migrations/2026_11_15_000000_add_link_secret_hash_to_two_factor_attempts.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('synthetic_host_attempts', 'link_secret_hash'));
        $this->assertNull(DB::table('synthetic_host_attempts')->sole()->link_secret_hash);
        $this->assertSame('synthetic-existing-attempt', DB::table('synthetic_host_attempts')->sole()->token);
        // The host migration does not discover or mutate an embedded provider's tables.
        $this->assertFalse(Schema::hasTable('auth_manager_two_factor_attempts'));
    }
}
