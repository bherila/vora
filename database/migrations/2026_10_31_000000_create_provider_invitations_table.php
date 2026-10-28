<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admission invitations this installation asked its identity provider to
        // send. The acceptance link is a bearer credential and is never stored.
        Schema::create('provider_invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('oauth_provider', 64);
            $table->unsignedBigInteger('provider_invitation_id');
            $table->foreignId('inviter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('invite_grant_id')->nullable()->constrained('invite_grants')->nullOnDelete();
            $table->string('email');
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->boolean('email_sent')->default(false);
            $table->timestamps();

            $table->unique(['oauth_provider', 'provider_invitation_id']);
            $table->index(['status', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_invitations');
    }
};
