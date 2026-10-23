<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->unsignedInteger('photo_width')->nullable();
            $table->unsignedInteger('photo_height')->nullable();
            $table->unsignedBigInteger('photo_pixel_area')->default(0);
            $table->unsignedTinyInteger('photo_metadata_score')->default(0);
            $table->json('photo_metadata')->nullable();
            $table->char('photo_group_ulid', 26)->nullable();
            $table->index(['event_id', 'photo_group_ulid'], 'media_event_photo_group_index');
            $table->index(['event_id', 'perceptual_hash'], 'media_event_photo_hash_index');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex('media_event_photo_group_index');
            $table->dropIndex('media_event_photo_hash_index');
            $table->dropColumn(['photo_width', 'photo_height', 'photo_pixel_area', 'photo_metadata_score', 'photo_metadata', 'photo_group_ulid']);
        });
    }
};
