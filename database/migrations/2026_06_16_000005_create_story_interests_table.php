<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Interest tags applied to a story by its authors (mirrors media_interests).
        Schema::create('story_interests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->foreignId('interest_id')->constrained('interests')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['story_id', 'interest_id']);
            $table->index('interest_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_interests');
    }
};
