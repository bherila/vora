<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * *Your activity* filters by author and paginates by cursor on
 * (created_at DESC, id DESC). Composite indexes leading with the filtered
 * column let the cursor walk the index instead of filesorting the page.
 *
 * The existing single-column posts.user_id index is deliberately left in
 * place. It backs the users foreign key, so dropping it needs the same
 * create-before-drop ordering as the canonical index swap, for a saving that
 * does not justify touching a constraint-backing index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at', 'id'], 'posts_author_timeline_index');
        });

        Schema::table('post_comments', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at', 'id'], 'post_comments_author_timeline_index');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex('posts_author_timeline_index');
        });

        Schema::table('post_comments', function (Blueprint $table): void {
            $table->dropIndex('post_comments_author_timeline_index');
        });
    }
};
