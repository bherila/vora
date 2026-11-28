<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Duration and frame rate copied from the transcoder's metadata.json, so the
     * app can bound chapter times and players can step whole frames without
     * reading the HLS bucket on every request.
     */
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->unsignedInteger('video_duration_ms')->nullable();
            $table->string('video_frame_rate', 32)->nullable();
            $table->timestamp('video_metadata_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn(['video_duration_ms', 'video_frame_rate', 'video_metadata_checked_at']);
        });
    }
};
