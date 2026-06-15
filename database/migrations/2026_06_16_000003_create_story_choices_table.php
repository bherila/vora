<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Directed edges of a CYOA graph: a labelled choice on one node that
        // leads to another node. A null target marks a terminal choice (ending).
        Schema::create('story_choices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->foreignId('from_node_id')->constrained('story_nodes')->cascadeOnDelete();
            $table->foreignId('to_node_id')->nullable()->constrained('story_nodes')->nullOnDelete();
            $table->string('label');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['story_id']);
            $table->index(['from_node_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_choices');
    }
};
