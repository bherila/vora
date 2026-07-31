<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('notify_message')->default(true)->after('notify_favorite');
        });

        Schema::table('reports', function (Blueprint $table): void {
            // Immutable, bounded evidence lets moderation retain the reported
            // message after live chat is purged without retaining a whole thread.
            $table->json('evidence')->nullable()->after('details');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn('evidence');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('notify_message');
        });
    }
};
