<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Public identifier used in object keys and view URLs (avoids
            // exposing/enumerating sequential ids).
            $table->ulid('ulid')->unique();

            $table->string('type'); // App\Enums\MediaType
            $table->string('disk'); // filesystem disk the source object lives on
            $table->string('object_key');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('title')->nullable();

            // Upload lifecycle for direct-to-storage uploads: pending until the
            // client confirms the object landed.
            $table->string('upload_status')->default('pending');

            // Owner-facing privacy. App\Enums\Visibility.
            $table->string('visibility')->default('unlisted');

            // Internal admin review. Never exposed to the uploader.
            $table->string('moderation_status')->default('pending');
            $table->foreignId('moderated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->text('moderation_notes')->nullable();

            $table->timestamps();

            $table->index('user_id');
            $table->index('moderation_status');
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
