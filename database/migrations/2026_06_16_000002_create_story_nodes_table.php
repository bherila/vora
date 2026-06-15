<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Passages of a choose-your-own-adventure story. Each node holds a chunk
        // of markdown; reader choices (story_choices) connect nodes into a graph.
        Schema::create('story_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            // Stable identifier for the node within its story, used by the editor
            // to reconcile choices on a full-graph save.
            $table->string('key', 64);
            $table->string('title')->nullable();
            $table->longText('body')->nullable();
            // Exactly one node per CYOA story is the entry point.
            $table->boolean('is_start')->default(false);
            // Optional canvas coordinates for the graph editor layout.
            $table->float('position_x')->default(0);
            $table->float('position_y')->default(0);
            $table->timestamps();

            $table->unique(['story_id', 'key']);
            $table->index(['story_id', 'is_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_nodes');
    }
};
