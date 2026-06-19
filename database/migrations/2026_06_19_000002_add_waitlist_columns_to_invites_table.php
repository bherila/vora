<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns for invites minted by an admin admitting a waitlist request:
     *  - `auto_approve`: the new account skips the admin-approval gate (the admin
     *    already vetted the requester), independent of the trusted-inviter flag.
     *  - `email`: the invite is bound to the verified waitlist email; registration
     *    locks the email field to it and carries the verification over.
     */
    public function up(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->boolean('auto_approve')->default(false)->after('invited_user_id');
            $table->string('email')->nullable()->after('auto_approve');
        });
    }

    public function down(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->dropColumn(['auto_approve', 'email']);
        });
    }
};
