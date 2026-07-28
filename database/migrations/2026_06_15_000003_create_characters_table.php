<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_picture_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->boolean('is_linked')->default(true);
            $table->string('gender')->nullable();
            $table->string('gender_other', 100)->nullable();
            $table->json('preferred_user_types')->nullable();
            $table->json('preferred_genders')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'display_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
