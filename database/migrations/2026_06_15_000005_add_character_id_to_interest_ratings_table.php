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

        Schema::table('interest_ratings', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'interest_id']);
        });

        // A unique index on character_id directly would not constrain the
        // user-profile rows, because SQLite and MySQL both treat NULLs as
        // distinct in a unique index. Index a generated key that folds "no
        // character" to 0 so (user, profile-or-character, interest) is unique
        // on both engines.
        Schema::table('interest_ratings', function (Blueprint $table): void {
            $table->unsignedBigInteger('character_key')->virtualAs('coalesce(character_id, 0)');
            $table->unique(['user_id', 'character_key', 'interest_id']);
        });
    }

    public function down(): void
    {
        Schema::table('interest_ratings', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'character_key', 'interest_id']);
            $table->dropColumn('character_key');
        });

        Schema::table('interest_ratings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('character_id');
            $table->unique(['user_id', 'interest_id']);
        });
    }
};
