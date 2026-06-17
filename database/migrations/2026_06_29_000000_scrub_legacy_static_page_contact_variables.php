<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Built-in legal pages used to seed app_name/privacy_contact_email into their
 * stored `variables` JSON. StaticPageRenderer lets stored variables override live
 * config, so installs seeded before that changed keep rendering a frozen contact
 * even after PRIVACY_CONTACT_EMAIL is updated. Strip those keys from the built-in
 * rows so the live-config values take effect; page-specific keys (e.g. the fixed
 * last_updated date) are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('static_pages')) {
            return;
        }

        $rows = DB::table('static_pages')
            ->whereIn('slug', ['home', 'privacy', 'terms'])
            ->get(['id', 'variables']);

        foreach ($rows as $row) {
            $variables = json_decode((string) ($row->variables ?? '{}'), true);
            if (! is_array($variables)) {
                continue;
            }

            $cleaned = $variables;
            unset($cleaned['app_name'], $cleaned['privacy_contact_email']);

            if ($cleaned === $variables) {
                continue;
            }

            DB::table('static_pages')
                ->where('id', $row->id)
                ->update(['variables' => json_encode($cleaned, JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        // Irreversible: the removed values came from live config and are not worth
        // re-freezing. No-op.
    }
};
