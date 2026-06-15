<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->string('purpose')->default('gallery')->after('type')->index();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('profile_picture_media_id')
                ->nullable()
                ->after('last_media_interest_ids')
                ->constrained('media')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('profile_picture_media_id');
        });

        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
    }
};
