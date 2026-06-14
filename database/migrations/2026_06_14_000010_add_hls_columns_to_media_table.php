<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            // Cached resolution of the transcoder's content-addressed output for a
            // video, so playback doesn't re-read the mapping object every request.
            $table->string('hls_content_id')->nullable()->after('mime_type');
            $table->timestamp('hls_checked_at')->nullable()->after('hls_content_id');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn(['hls_content_id', 'hls_checked_at']);
        });
    }
};
