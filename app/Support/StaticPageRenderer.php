<?php

namespace App\Support;

use App\Models\StaticPage;
use App\Services\Auth\AgeEligibility;
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
        ], is_array($variables) ? $variables : [], ['age_eligibility' => app(AgeEligibility::class)->terms()]);
    }

    public static function renderMarkdown(string $markdown, array $variables): string
    {
        // Existing seeded boilerplate predates the configurable policy. Upgrade
        // that exact sentence on render; leave organizer-authored prose intact.
        $markdown = str_replace(
            'You must be at least 18 years old or the age of majority in your local jurisdiction, whichever is higher, and legally able to enter into these terms.',
            '{{age_eligibility}} You must be legally able to use the service under applicable requirements, with a parent or guardian’s involvement where required.',
            $markdown,
        );
        foreach ($variables as $key => $value) {
            $markdown = str_replace('{{'.$key.'}}', (string) $value, $markdown);
        }

        $parsedown = new Parsedown;
        $parsedown->setSafeMode(true);

        return $parsedown->text($markdown);
    }
}
