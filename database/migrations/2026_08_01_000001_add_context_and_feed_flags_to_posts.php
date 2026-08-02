<?php

use App\Models\Interest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->foreignId('context_interest_id')->nullable()->constrained('interests')->nullOnDelete();
            $table->boolean('is_feed_hidden')->default(false);
            $table->unsignedInteger('comment_revision')->default(0);
            $table->index(['context_interest_id', 'created_at', 'id']);
        });

        DB::table('post_attachments')
            ->where('attachable_type', (new Interest)->getMorphClass())
            ->orderBy('id')
            ->get(['post_id', 'attachable_id'])
            ->unique('post_id')
            ->each(fn (object $row) => DB::table('posts')->where('id', $row->post_id)->update([
                'context_interest_id' => $row->attachable_id,
            ]));
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropForeign(['context_interest_id']);
            $table->dropIndex(['context_interest_id', 'created_at', 'id']);
            $table->dropColumn(['context_interest_id', 'is_feed_hidden', 'comment_revision']);
        });
    }
};
