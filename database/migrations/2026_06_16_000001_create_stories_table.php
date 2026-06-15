<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->ulid('ulid')->unique();
            $table->string('title');
            // long_form | cyoa
            $table->string('type', 20)->default('long_form');
            // draft | published
            $table->string('status', 20)->default('draft');
            // Markdown body for long-form stories (and optional intro for CYOA).
            $table->longText('body')->nullable();
            // users | unlisted (shared HasVisibility trait).
            $table->string('visibility', 20)->default('users');
            // pending | approved | rejected (shared Moderatable trait).
            $table->string('moderation_status', 20)->default('pending');
            $table->foreignId('moderated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->text('moderation_notes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['visibility', 'status']);
            $table->index('moderation_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};
