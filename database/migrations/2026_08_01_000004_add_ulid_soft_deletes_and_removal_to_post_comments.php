<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_comments', function (Blueprint $table): void {
            $table->ulid('ulid')->nullable();
            $table->softDeletes();
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
        });

        DB::table('post_comments')->orderBy('id')->get(['id'])->each(
            fn (object $row) => DB::table('post_comments')->where('id', $row->id)->update(['ulid' => (string) Str::ulid()]),
        );

        Schema::table('post_comments', function (Blueprint $table): void {
            $table->ulid('ulid')->nullable(false)->change();
            $table->unique('ulid');
            $table->dropForeign(['parent_id']);
            $table->foreign('parent_id')->references('id')->on('post_comments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('post_comments', function (Blueprint $table): void {
            $table->dropForeign(['parent_id']);
            $table->foreign('parent_id')->references('id')->on('post_comments')->nullOnDelete();
            $table->dropForeign(['removed_by_user_id']);
            $table->dropUnique(['ulid']);
            $table->dropColumn(['ulid', 'deleted_at', 'removed_by_user_id', 'removed_at']);
        });
    }
};
