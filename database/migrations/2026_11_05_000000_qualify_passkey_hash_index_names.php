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
        $indexes = collect(Schema::getIndexes($tableName))->keyBy('name');
        foreach (['unique', 'index'] as $kind) {
            $old = 'passkey_credential_id_hash_'.$kind;
            $new = $tableName.'_credential_id_hash_'.$kind;
            $original = $indexes->get($old);
            if ($original === null) {
                continue;
            }
            $qualified = $indexes->get($new);
            if ($original['columns'] !== ['credential_id_hash'] || $original['unique'] !== ($kind === 'unique')
                || ($qualified !== null && ($qualified['columns'] !== ['credential_id_hash'] || ($kind === 'unique' && ! $qualified['unique'])))) {
                throw new RuntimeException('A passkey hash index has an unexpected definition; its uniqueness was left intact.');
            }
            $qualifiedExists = $qualified !== null;
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
