<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // The identity provider's name for itself (OAUTH_PROVIDER) and its
            // opaque subject. Provider sign-in binds on this pair, never email.
            $table->string('oauth_provider', 64)->nullable()->after('email');
            $table->string('oauth_subject', 255)->nullable()->after('oauth_provider');
            $table->unique(['oauth_provider', 'oauth_subject']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['oauth_provider', 'oauth_subject']);
            $table->dropColumn(['oauth_provider', 'oauth_subject']);
        });
    }
};
