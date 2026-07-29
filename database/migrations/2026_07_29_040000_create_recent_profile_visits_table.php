<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recent_profile_visits')) {
            Schema::create('recent_profile_visits', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('viewer_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('target_type', 20);
                $table->unsignedBigInteger('target_id');
                $table->timestamp('visited_at', 6);
                $table->timestamps();
            });
        }

        // MySQL created the table before rejecting Laravel's generated unique
        // name (it exceeded the 64-character identifier limit). Keep this
        // migration resumable so the next deploy can finish that partial run.
        if (! Schema::hasIndex('recent_profile_visits', 'recent_profile_visits_target_unique')) {
            Schema::table('recent_profile_visits', function (Blueprint $table): void {
                $table->unique(
                    ['viewer_user_id', 'target_type', 'target_id'],
                    'recent_profile_visits_target_unique',
                );
            });
        }

        if (! Schema::hasIndex('recent_profile_visits', 'recent_profile_visits_recent_index')) {
            Schema::table('recent_profile_visits', function (Blueprint $table): void {
                $table->index(
                    ['viewer_user_id', 'visited_at'],
                    'recent_profile_visits_recent_index',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recent_profile_visits');
    }
};
