<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Amended in place because the original never completed anywhere. On
        // MySQL the CREATE TABLE succeeded and the follow-up ALTER TABLE failed
        // — the auto-generated index name exceeded MySQL's 64-character
        // identifier limit, which SQLite does not enforce — so the migration was
        // never recorded as run and re-runs (and re-fails) on every deploy.
        // Dropping first makes that re-run idempotent over the partial table;
        // it is new and holds only ephemeral, 30-day-capped browsing history.
        Schema::dropIfExists('recent_profile_visits');

        Schema::create('recent_profile_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('viewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->timestamp('visited_at', 6);
            $table->timestamps();

            // Names are explicit and short. The generated defaults concatenate
            // the table with every indexed column and overflow on MySQL.
            $table->unique(['viewer_user_id', 'target_type', 'target_id'], 'rpv_viewer_target_unique');
            $table->index(['viewer_user_id', 'visited_at'], 'rpv_viewer_visited_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recent_profile_visits');
    }
};
