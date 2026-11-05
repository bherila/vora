<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('bherila-auth.passkeys.table', 'auth_passkeys');
        if (! Schema::hasTable($tableName)) {
            return;
        }

        // SQLite names indexes across the whole database. The provider's separate
        // passkey table must not collide with the host's former generic names.
        foreach (['unique', 'index'] as $kind) {
            $old = 'passkey_credential_id_hash_'.$kind;
            $new = $tableName.'_credential_id_hash_'.$kind;
            if (! Schema::hasIndex($tableName, $old)) {
                continue;
            }
            $qualifiedExists = Schema::hasIndex($tableName, $new);
            Schema::table($tableName, static function (Blueprint $table) use ($old, $new, $kind, $qualifiedExists): void {
                // Fresh hosts already have the generated qualified index as well.
                if ($qualifiedExists) {
                    $kind === 'unique' ? $table->dropUnique($old) : $table->dropIndex($old);
                } else {
                    $table->renameIndex($old, $new);
                }
            });
        }
    }

    public function down(): void
    {
        // Keep the qualified names: a provider may now own the old generic name.
        // No columns or uniqueness guarantees changed, so older code still works.
    }
};
