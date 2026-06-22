<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            // Exact-bytes hash (client-computed SHA-256, hex) for deduplicating
            // identical re-uploads. Indexed for the per-owner duplicate lookup.
            $table->string('file_hash', 64)->nullable()->after('perceptual_hash');
            $table->index(['user_id', 'file_hash']);

            // Soft pointer to an earlier item this one is a likely duplicate of —
            // set when a near-identical perceptual hash (photos) or transcoder
            // content id (videos) is detected. Surfaced to admins, never blocking.
            $table->foreignId('duplicate_of_media_id')->nullable()->after('file_hash')
                ->constrained('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropForeign(['duplicate_of_media_id']);
            $table->dropColumn('duplicate_of_media_id');
            $table->dropIndex(['user_id', 'file_hash']);
            $table->dropColumn('file_hash');
        });
    }
};
