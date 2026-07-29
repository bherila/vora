<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recent_profile_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('viewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->timestamp('visited_at', 6);
            $table->timestamps();

            $table->unique(['viewer_user_id', 'target_type', 'target_id']);
            $table->index(['viewer_user_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recent_profile_visits');
    }
};
