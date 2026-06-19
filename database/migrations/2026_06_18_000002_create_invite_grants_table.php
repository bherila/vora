<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An admin issuance of invites to a single user. `remaining` is decremented
     * as the holder generates shareable invite links. Unused grants lapse at
     * `expires_at` (admin-configured) whether or not they are handed out.
     */
    public function up(): void
    {
        Schema::create('invite_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('remaining');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_grants');
    }
};
