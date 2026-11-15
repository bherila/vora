<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand-only: a nullable column the previous release never reads, so code and
 * schema can be deployed in either order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('pending_email')->nullable()->after('email');
            $table->timestamp('pending_email_requested_at')->nullable()->after('pending_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['pending_email', 'pending_email_requested_at']);
        });
    }
};
