<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Admin flag (user id 1 is always treated as admin regardless).
            $table->boolean('is_admin')->default(false)->after('password');
            // Hard disable / reject — blocks all login.
            $table->boolean('is_disabled')->default(false)->after('is_admin');
            // Admin approval gate: null = pending approval, set = approved.
            $table->timestamp('approved_at')->nullable()->after('is_disabled');
            $table->foreignId('approved_by_user_id')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();
            // Forces the user to set a new password before using the app.
            $table->boolean('force_change_pw')->default(false)->after('approved_by_user_id');
        });

        // Grandfather every pre-existing account as approved. These users predate the
        // approval gate; leaving approved_at null would redirect them (including the
        // primary admin, id 1) to /pending-approval and lock existing deployments out.
        DB::table('users')->whereNull('approved_at')->update(['approved_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropColumn(['is_admin', 'is_disabled', 'approved_at', 'force_change_pw']);
        });
    }
};
