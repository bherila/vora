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
        Schema::create('interest_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('parent_interest_id')->nullable()
                ->constrained('interests')
                ->nullOnDelete();
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by_user_id')->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('user_id');
            $table->index('parent_interest_id');
            $table->index('reviewed_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interest_requests');
    }
};
