<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_HOME_MARKDOWN = "# Welcome to {{app_name}}\n\n{{app_name}} is a private, invite-only community for creating, organizing, and sharing media, characters, stories, and interests.\n\nMembership is by invitation. If you'd like to join, you can [request an invitation](/request-invitation) and we'll review your request.\n\nUse the admin static page editor to replace this boilerplate home page with launch-ready copy.";

    private const NEW_HOME_MARKDOWN = "{{app_name}} is a private, invite-only community for creating, organizing, and sharing media, characters, stories, and interests.\n\nMembership is by invitation. If you'd like to join, you can [request an invitation](/request-invitation) and we'll review your request.";

    public function up(): void
    {
        if (! Schema::hasTable('static_pages')) {
            return;
        }

        $updates = ['title' => config('app.name')];

        if (Schema::hasColumn('static_pages', 'updated_at')) {
            $updates['updated_at'] = now();
        }

        DB::table('static_pages')
            ->where('slug', 'home')
            ->whereIn('body_markdown', [self::OLD_HOME_MARKDOWN, self::NEW_HOME_MARKDOWN])
            ->update($updates);
    }

    public function down(): void
    {
        // Irreversible: the stale stored title came from whatever APP_NAME was at
        // seed time. Do not reintroduce a guessed legacy value.
    }
};
