<?php

use App\Models\Media;
use App\Models\Story;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['media', 'stories'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                // Portable "at most one": nullable unique works on SQLite and MariaDB.
                $table->foreignId('canonical_post_id')->nullable()->constrained('posts')->nullOnDelete();
                $table->unique('canonical_post_id');
            });
        }

        $this->backfill('media', (new Media)->getMorphClass());
        $this->backfill('stories', (new Story)->getMorphClass());
    }

    public function down(): void
    {
        foreach (['stories', 'media'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['canonical_post_id']);
                $table->dropUnique(['canonical_post_id']);
                $table->dropColumn('canonical_post_id');
            });
        }
    }

    private function backfill(string $tableName, string $morphClass): void
    {
        DB::table('post_attachments')
            ->join('posts', 'posts.id', '=', 'post_attachments.post_id')
            ->where('post_attachments.attachable_type', $morphClass)
            ->whereNull('posts.deleted_at')
            ->orderBy('post_attachments.id')
            ->get(['post_attachments.attachable_id', 'post_attachments.post_id'])
            ->unique('attachable_id')
            ->each(fn (object $row) => DB::table($tableName)->where('id', $row->attachable_id)->update([
                'canonical_post_id' => $row->post_id,
            ]));
    }
};
