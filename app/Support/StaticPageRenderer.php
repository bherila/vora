<?php

namespace App\Support;

use App\Models\StaticPage;
use Parsedown;

class StaticPageRenderer
{
    /**
     * @return array<string, string>
     */
    public static function variables(StaticPage|array $page): array
    {
        $variables = $page instanceof StaticPage ? $page->variablesArray() : ($page['variables'] ?? []);

        return array_merge([
            'app_name' => config('app.name'),
            'privacy_contact_email' => config('app.privacy_contact_email'),
            // "Last updated" reflects when the content actually changed, not the
            // current request date: a stored page falls back to its updated_at, a
            // built-in default to its fixed revision date. A page's own variables
            // (below) still override this when they declare last_updated.
            'last_updated' => $page instanceof StaticPage
                ? ($page->updated_at?->format('F j, Y') ?? DefaultStaticPages::REVISION_DATE)
                : DefaultStaticPages::REVISION_DATE,
        ], is_array($variables) ? $variables : []);
    }

    public static function renderMarkdown(string $markdown, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $markdown = str_replace('{{'.$key.'}}', (string) $value, $markdown);
        }

        $parsedown = new Parsedown;
        $parsedown->setSafeMode(true);

        return $parsedown->text($markdown);
    }
}
