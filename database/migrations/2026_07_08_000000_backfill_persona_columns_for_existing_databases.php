<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Repairs databases that ran the original character/story-author migrations
 * before #112 amended them in place.
 *
 * #112 added `characters.ulid`, `characters.is_linked`, and
 * `story_authors.character_id` by editing the original `create_*` migrations
 * rather than layering a new one — correct for a fresh install, but any database
 * that had already run those migrations records them as complete, so Laravel
 * skips them and the columns are never created. Production auto-deploys from
 * main and had run them since June, so it was left without columns the deployed
 * code requires (Character::creating writes ulid/is_linked, CharacterPresenter
 * selects both, InterestController branches on is_linked).
 *
 * Every step is guarded by a hasColumn() check, so this is a no-op on a fresh
 * install where the amended create migrations already produced the columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('characters', 'ulid')) {
            // Added nullable so existing rows survive the ALTER, then backfilled
            // and tightened — a unique index cannot be added over NULLs.
            Schema::table('characters', function (Blueprint $table): void {
                $table->ulid('ulid')->nullable()->after('id');
            });

            DB::table('characters')->select('id')->whereNull('ulid')->orderBy('id')
                ->chunkById(500, function ($rows): void {
                    foreach ($rows as $row) {
                        DB::table('characters')->where('id', $row->id)->update(['ulid' => (string) Str::ulid()]);
                    }
                });

            Schema::table('characters', function (Blueprint $table): void {
                $table->ulid('ulid')->nullable(false)->change();
                $table->unique('ulid');
            });
        }

        if (! Schema::hasColumn('characters', 'is_linked')) {
            Schema::table('characters', function (Blueprint $table): void {
                $table->boolean('is_linked')->default(true);
            });
        }

        if (! Schema::hasColumn('story_authors', 'character_id')) {
            Schema::table('story_authors', function (Blueprint $table): void {
                // cascadeOnDelete, not the repo's usual nullOnDelete: a persona's
                // authorship rows must not silently revert to the human author.
                $table->foreignId('character_id')->nullable()->constrained('characters')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Intentionally irreversible. Rolling back would drop columns that a
        // fresh install creates in its original create_* migrations, leaving the
        // schema inconsistent depending on which path built it.
    }
};
