<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Authorship of a story. The creator is the single `owner`; additional
        // users are invited as `co_author` and must accept (status `accepted`)
        // before they can edit. Pending invites flow through the shared
        // acceptance inbox alongside follow requests.
        Schema::create('story_authors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('character_id')->nullable()->constrained('characters')->cascadeOnDelete();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // owner | co_author
            $table->string('role', 20)->default('co_author');
            // pending | accepted  (owners are created already accepted)
            $table->string('status', 20)->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['story_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index(['story_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_authors');
    }
};
