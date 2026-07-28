<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->boolean('is_announcement')->default(false)->after('discoverable');
        });

        Schema::table('media', function (Blueprint $table): void {
            // Existing rows must not be announced retroactively. New uploads
            // explicitly opt in through StoreMediaRequest's default.
            $table->boolean('announce_on_approval')->default(false)->after('discoverable');
        });

        Schema::table('stories', function (Blueprint $table): void {
            // Existing published stories stay quiet; newly-created stories opt
            // in from StoryController.
            $table->boolean('announce_on_approval')->default(false)->after('discoverable');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropColumn('announce_on_approval');
        });

        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn('announce_on_approval');
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn('is_announcement');
        });
    }
};
