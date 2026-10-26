<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('mailer', 32);
            // The provider's id for the send: Brevo's API message id, SES's MessageId,
            // or the generated Message-ID for transports that never report back.
            $table->string('provider_message_id', 255)->index();
            $table->string('recipient', 254);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Mailable or notification class basename; subjects and bodies are never
            // stored because they can carry sign-in codes and links.
            $table->string('type', 100)->nullable();
            $table->string('status', 20)->default('sent');
            $table->string('status_reason', 255)->nullable();
            $table->timestamp('sent_at');
            $table->timestamp('status_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'sent_at']);
            $table->index(['user_id', 'sent_at']);
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_deliveries');
    }
};
