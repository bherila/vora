<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports the feed's ordering path: candidate posts are seeked newest-first by
 * (created_at, id), so an order-compatible composite index lets the timeline
 * query seek through an index instead of sorting the matching rows per request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'posts_timeline_index');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex('posts_timeline_index');
        });
    }
};
