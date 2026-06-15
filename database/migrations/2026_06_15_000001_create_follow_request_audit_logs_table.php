<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_request_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('follow_request_id')->nullable()->constrained('follow_requests')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 30);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['requester_id', 'recipient_id']);
            $table->index(['recipient_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_request_audit_logs');
    }
};
