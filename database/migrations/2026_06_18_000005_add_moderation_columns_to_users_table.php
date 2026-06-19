<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Moderation levers for abuse surfaced via the invite tree:
     *  - ban: account can still log in but is gated to appeal/deactivate/delete.
     *    `ban_hides_content` optionally hides their content (vs. memorialized).
     *  - legal hold: admin-only, blocks account deletion (only) regardless of ban.
     *  - trusted_inviter: this user's invitees skip the admin approval gate.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('banned_at')->nullable()->after('can_receive_invites');
            $table->foreignId('banned_by_user_id')->nullable()->after('banned_at')
                ->constrained('users')->nullOnDelete();
            $table->text('ban_reason')->nullable()->after('banned_by_user_id');
            $table->boolean('ban_hides_content')->default(false)->after('ban_reason');
            $table->text('ban_appeal_message')->nullable()->after('ban_hides_content');
            $table->timestamp('ban_appeal_at')->nullable()->after('ban_appeal_message');

            $table->timestamp('legal_hold_at')->nullable()->after('ban_appeal_at');
            $table->foreignId('legal_hold_by_user_id')->nullable()->after('legal_hold_at')
                ->constrained('users')->nullOnDelete();
            $table->text('legal_hold_note')->nullable()->after('legal_hold_by_user_id');

            $table->boolean('trusted_inviter')->default(false)->after('legal_hold_note');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('banned_by_user_id');
            $table->dropConstrainedForeignId('legal_hold_by_user_id');
            $table->dropColumn([
                'banned_at',
                'ban_reason',
                'ban_hides_content',
                'ban_appeal_message',
                'ban_appeal_at',
                'legal_hold_at',
                'legal_hold_note',
                'trusted_inviter',
            ]);
        });
    }
};
