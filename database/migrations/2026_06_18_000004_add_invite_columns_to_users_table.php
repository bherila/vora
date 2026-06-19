<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links a user back to the invite that referred them (null = signed up
     * without an invite) and tracks whether the admin permits them to receive
     * future invite grants. Added after the invites table so the FK resolves.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('referred_by_invite_id')->nullable()->after('approved_by_user_id')
                ->constrained('invites')->nullOnDelete();
            $table->boolean('can_receive_invites')->default(true)->after('referred_by_invite_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_invite_id');
            $table->dropColumn('can_receive_invites');
        });
    }
};
