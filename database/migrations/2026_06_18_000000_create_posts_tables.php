<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short text posts (the feed building block) plus their polymorphic attachments.
 * Posts reuse the shared privacy (audience/discoverable) and moderation columns,
 * exactly like Media and Story.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->ulid('ulid')->unique();
            $table->text('body');
            // Privacy (shared HasPrivacyPolicy trait).
            $table->string('audience', 20)->default('everyone');
            $table->boolean('discoverable')->default(true);
            // Admin review (shared Moderatable trait).
            $table->string('moderation_status', 20)->default('pending');
            $table->foreignId('moderated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->text('moderation_notes')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index(['audience', 'discoverable']);
            $table->index('moderation_status');
        });

        Schema::create('post_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->morphs('attachable');
            $table->timestamps();

            $table->unique(['post_id', 'attachable_type', 'attachable_id'], 'post_attachments_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_attachments');
        Schema::dropIfExists('posts');
    }
};
