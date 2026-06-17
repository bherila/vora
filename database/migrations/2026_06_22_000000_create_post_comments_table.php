<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replies on a post. Flat with one optional level of threading (parent_id), and
 * the shared Moderatable columns so a comment can be taken down reactively.
 * Cascades with the post; a deleted parent comment leaves its replies as
 * top-level (parent_id null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('post_comments')->nullOnDelete();
            $table->text('body');
            // Admin review (shared Moderatable trait).
            $table->string('moderation_status', 20)->default('pending');
            $table->foreignId('moderated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->text('moderation_notes')->nullable();
            $table->timestamps();

            $table->index(['post_id', 'moderation_status']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_comments');
    }
};
