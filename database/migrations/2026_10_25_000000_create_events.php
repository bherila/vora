<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('visibility')->default('everyone');
            $table->string('timezone')->default('UTC');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('event_user', function (Blueprint $table): void {
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['event_id', 'user_id']);
            $table->index(['user_id', 'event_id']);
        });
        Schema::table('media', function (Blueprint $table): void {
            $table->foreignId('event_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('event_id');
        });
        Schema::dropIfExists('event_user');
        Schema::dropIfExists('events');
    }
};
