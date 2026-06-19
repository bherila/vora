<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A guest's request to join the private community ("request an invitation").
     * Captured with the Cloudflare-forwarded IP + geo. Email ownership is proven
     * by a verification link/code (`verified_at`). An admin then admits the
     * request — which mints an auto-approving invite (`invite_id`) emailed to the
     * requester — or deletes it.
     */
    public function up(): void
    {
        Schema::create('waitlist_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('email');
            $table->date('birth_date');
            $table->text('interests');
            $table->string('ip_address')->nullable();
            $table->json('geo')->nullable();
            $table->string('verification_code_hash')->nullable();
            $table->string('verification_token_hash')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('admitted_at')->nullable();
            $table->foreignId('admitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('invite_id')->nullable()->constrained('invites')->nullOnDelete();
            $table->timestamps();

            $table->index('email');
            $table->index('verified_at');
            $table->index('admitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_requests');
    }
};
