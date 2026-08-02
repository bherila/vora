<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `canonical_post_id` was given a unique index to express "a media item has at
 * most one canonical discussion post". A single-valued column already says
 * that. What the unique index actually added was the reverse constraint — "a
 * post is canonical for at most one media item" — which is wrong: a gallery
 * post carries several media attachments and is legitimately the canonical
 * discussion for all of them. Claiming the second attachment raised a unique
 * violation and failed the whole post.
 *
 * Replaced with a plain index, which is what the reverse lookup in
 * Post::deleting needs.
 *
 * The replacement index is created before the unique one is dropped, as a
 * separate statement. InnoDB requires the foreign key on this column to keep a
 * supporting index at all times and refuses to drop the last one, so the order
 * is load-bearing rather than stylistic. SQLite is indifferent — it rebuilds
 * the table either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['media', 'stories'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->index('canonical_post_id'));

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropUnique($tableName.'_canonical_post_id_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (['media', 'stories'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->unique('canonical_post_id'));

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropIndex($tableName.'_canonical_post_id_index');
            });
        }
    }
};
