<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interest_ratings', function (Blueprint $table): void {
            // A rating belongs to (user, character). character_id NULL is the
            // user's own profile rating; a non-null character_id is an override
            // for one of the user's characters.
            $table->foreignId('character_id')->nullable()->after('user_id')->constrained('characters')->cascadeOnDelete();
        });

        // A unique index on character_id directly would not constrain the
        // user-profile rows, because SQLite and MySQL both treat NULLs as
        // distinct in a unique index. Index a generated key that folds "no
        // character" to 0 so (user, profile-or-character, interest) is unique
        // on both engines.
        //
        // Create this replacement index BEFORE dropping the old
        // (user_id, interest_id) unique below: that old index is the only one
        // covering user_id, so MySQL refuses to drop it while the user_id
        // foreign key still depends on it (error 1553). This new index also
        // leads with user_id, so once it exists the FK is satisfied and the
        // old index can be dropped. SQLite doesn't enforce this ordering, which
        // is why the previous order passed the SQLite test suite but failed on
        // MySQL in production.
        Schema::table('interest_ratings', function (Blueprint $table): void {
            $table->unsignedBigInteger('character_key')->virtualAs('coalesce(character_id, 0)');
            $table->unique(['user_id', 'character_key', 'interest_id']);
        });

        Schema::table('interest_ratings', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'interest_id']);
        });
    }

    public function down(): void
    {
        // Re-create the old index before dropping the new one, for the same
        // FK-dependency reason as up() (the new index is the only one covering
        // user_id until the old one is restored).
        Schema::table('interest_ratings', function (Blueprint $table): void {
            $table->unique(['user_id', 'interest_id']);
        });

        Schema::table('interest_ratings', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'character_key', 'interest_id']);
            $table->dropColumn('character_key');
        });

        Schema::table('interest_ratings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('character_id');
        });
    }
};
