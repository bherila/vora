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
            'last_updated' => date('F j, Y'),
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
