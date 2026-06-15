<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            // Object key of a small, client-generated JPEG derivative (photo
            // thumbnail or captured video poster frame). Always stored on the
            // non-transcoded thumbnail disk so the video transcoder never scans
            // it. Null until/unless the client uploads one.
            $table->string('thumbnail_key')->nullable()->after('object_key');

            // Base64-encoded 32-byte perceptual (blockhash) hash of a photo,
            // computed client-side for future near-duplicate detection in
            // exploration/search. Indexed so a later dedup lookup stays cheap.
            $table->string('perceptual_hash', 64)->nullable()->after('mime_type');
            $table->index('perceptual_hash');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex(['perceptual_hash']);
            $table->dropColumn(['thumbnail_key', 'perceptual_hash']);
        });
    }
};
