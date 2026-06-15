<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Self-service deactivation: reversible, the account can still log in
            // (gated to a reactivate page) but is hidden from other users.
            $table->timestamp('deactivated_at')->nullable()->after('approved_at');
            // Self-service deletion is a soft delete; only an admin can restore
            // or hard-delete (purge) the account.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn('deactivated_at');
        });
    }
};
