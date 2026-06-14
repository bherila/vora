<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Remembers the interests selected on the user's most recent upload
            // so the picker can pre-fill them next time.
            $table->json('last_media_interest_ids')->nullable()->after('preferred_genders');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('last_media_interest_ids');
        });
    }
};
