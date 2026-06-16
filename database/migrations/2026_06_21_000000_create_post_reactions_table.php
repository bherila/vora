<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight reactions on a post. One row per (user, post, type); a single
 * "like" type to start, with `type` kept so the set can expand without a schema
 * change. Cascades on both the post and the user so a delete leaves no orphans.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->string('type', 20)->default('like');
            $table->timestamps();

            $table->unique(['user_id', 'post_id', 'type'], 'post_reactions_unique');
            $table->index(['post_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_reactions');
    }
};
