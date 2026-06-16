<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a post be published "as" one of the author's characters. Ownership and
 * moderation stay on the user account; the character is only the surfaced
 * identity. Deleting the character nulls the link, leaving the post coherent
 * under the user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->foreignId('character_id')->nullable()->after('user_id')
                ->constrained('characters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('character_id');
        });
    }
};
