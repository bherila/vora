<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('id_verified_at')->nullable()->after('email_verified_at');
            $table->boolean('name_locked')->default(false)->after('id_verified_at');
            $table->boolean('email_locked')->default(false)->after('name_locked');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['id_verified_at', 'name_locked', 'email_locked']);
        });
    }
};
