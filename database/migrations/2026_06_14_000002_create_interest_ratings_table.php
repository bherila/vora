<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('interest_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('interest_id')->constrained('interests')->cascadeOnDelete();
            $table->smallInteger('level');
            $table->timestamps();

            $table->unique(['user_id', 'interest_id']);
            $table->index('interest_id');

            $table->check('level >= -10 and level <= 10');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interest_ratings');
    }
};

