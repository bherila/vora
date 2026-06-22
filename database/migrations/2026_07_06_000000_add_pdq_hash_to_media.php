<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            // PDQ perceptual hash (256-bit, stored as 64 lowercase hex chars),
            // computed out-of-band by the image-hashing worker and read back by
            // the app — exactly like the video transcoder's hls_content_id. More
            // robust than the client blockhash (survives recompression and minor
            // crop/rotate). Used for per-owner near-duplicate flagging; the global
            // cross-account search (admin-only) is a later phase.
            $table->string('pdq_hash', 64)->nullable()->after('duplicate_of_media_id');

            // Last time we looked up this photo's PDQ mapping in the results
            // bucket. Rate-limits re-checks of a not-yet-hashed image, mirroring
            // hls_checked_at for videos.
            $table->timestamp('pdq_checked_at')->nullable()->after('pdq_hash');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn(['pdq_hash', 'pdq_checked_at']);
        });
    }
};
