<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A frame of a video that a viewer saved, with an optional caption. The
     * shared image is always extracted by the transcoder's frame worker from
     * approved HLS output (frame_status); the browser's own capture
     * (provisional_*) is only ever shown to its author until then.
     */
    public function up(): void
    {
        Schema::create('video_moments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->string('content_id', 100);
            $table->unsignedInteger('time_ms');
            $table->string('caption', 280)->nullable();
            $table->string('visibility', 16);
            $table->string('frame_status', 16);
            // When the frame was last asked for: an error marker older than
            // this belongs to an earlier attempt and is ignored.
            $table->timestamp('frame_requested_at')->nullable();
            $table->timestamp('frame_checked_at')->nullable();
            $table->string('provisional_key')->nullable();
            $table->string('provisional_status', 16)->nullable();
            $table->unsignedInteger('provisional_size_bytes')->nullable();
            $table->timestamps();

            $table->index(['media_id', 'time_ms']);
            $table->index(['frame_status', 'created_at']);
            $table->index(['content_id', 'time_ms']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_moments');
    }
};
