<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->string('audience', 20)->default('everyone')->after('profile_picture_media_id');
            $table->boolean('discoverable')->default(true)->after('audience');
            $table->index(['audience', 'discoverable'], 'characters_audience_discoverable_index');
        });

        Schema::table('media', function (Blueprint $table): void {
            $table->foreignId('character_id')
                ->nullable()
                ->after('user_id')
                ->constrained('characters')
                ->nullOnDelete();
        });

        DB::table('characters')
            ->whereNotNull('profile_picture_media_id')
            ->orderBy('id')
            ->chunkById(100, function ($characters): void {
                foreach ($characters as $character) {
                    DB::table('media')
                        ->where('id', $character->profile_picture_media_id)
                        ->update(['character_id' => $character->id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('character_id');
        });

        Schema::table('characters', function (Blueprint $table): void {
            $table->dropIndex('characters_audience_discoverable_index');
            $table->dropColumn(['audience', 'discoverable']);
        });
    }
};
