<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mutes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('muted_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('muted_character_id')->nullable()->constrained('characters')->cascadeOnDelete();
            $table->timestamps();

            // Exactly one target column is populated by MuteController. Separate
            // nullable unique indexes work on both SQLite and MySQL: each real
            // target is unique, while the unrelated NULL column is ignored.
            $table->unique(['user_id', 'muted_user_id'], 'mutes_user_target_unique');
            $table->unique(['user_id', 'muted_character_id'], 'mutes_character_target_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mutes');
    }
};
