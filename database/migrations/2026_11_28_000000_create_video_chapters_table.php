<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named start times within a video, curated by its uploader. A chapter ends
     * where the next begins (or at the end of the video). Author columns are
     * cleared when the publication is released but Library saves keep the video,
     * so the chapters survive anonymized.
     */
    public function up(): void
    {
        Schema::create('video_chapters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->unsignedInteger('start_ms');
            $table->string('title', 120);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->timestamps();

            $table->unique(['media_id', 'start_ms']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_chapters');
    }
};
