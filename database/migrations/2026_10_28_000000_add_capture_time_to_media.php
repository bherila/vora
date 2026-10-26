<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->timestamp('captured_at')->nullable();
            // Capture time when the device reported one, otherwise upload time.
            $table->timestamp('gallery_sort_at')->nullable();
            $table->index(['event_id', 'gallery_sort_at', 'id'], 'media_event_gallery_sort_index');
        });
        DB::table('media')->whereNull('gallery_sort_at')->update(['gallery_sort_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex('media_event_gallery_sort_index');
            $table->dropColumn(['captured_at', 'gallery_sort_at']);
        });
    }
};
