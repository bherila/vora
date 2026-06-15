<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_interest_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('character_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('interest_id')->constrained('interests')->cascadeOnDelete();
            $table->smallInteger('level');
            $table->timestamps();

            $table->unique(['character_id', 'interest_id']);
            $table->index('interest_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_interest_ratings');
    }
};
