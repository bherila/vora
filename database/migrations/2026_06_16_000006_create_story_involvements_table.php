<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Involves" tags: a story may involve authoring users and/or their
        // characters. Polymorphic so a single tag set spans both entity types.
        Schema::create('story_involvements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->string('involvable_type');
            $table->unsignedBigInteger('involvable_id');
            $table->timestamps();

            $table->unique(['story_id', 'involvable_type', 'involvable_id'], 'story_involvements_unique');
            $table->index(['involvable_type', 'involvable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_involvements');
    }
};
