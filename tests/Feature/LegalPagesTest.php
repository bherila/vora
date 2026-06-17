<?php

namespace Tests\Feature;

use App\Models\StaticPage;
use App\Models\User;
use App\Support\DefaultStaticPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_page_is_public_and_uses_configured_contact(): void
    {
        config(['app.privacy_contact_email' => 'privacy@example.test']);

        $this->get('/privacy')
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('privacy@example.test')
            ->assertSee('California residents')
            ->assertSee('GDPR');
    }

    public function test_terms_page_is_public(): void
    {
        $this->get('/terms')
            ->assertOk()
            ->assertSee('Terms of Service')
            ->assertSee('User content')
            ->assertSee('Acceptable use')
            ->assertSee('at least 18 years old or the age of majority');
    }

    public function test_static_page_content_can_be_loaded_from_database_with_variables(): void
    {
        StaticPage::query()->create([
            'slug' => 'privacy',
            'title' => 'Custom Privacy',
            'body_markdown' => '# {{headline}}\n\nContact {{privacy_contact_email}}.',
            'variables' => json_encode(['headline' => 'Database Privacy'], JSON_THROW_ON_ERROR),
            'is_published' => true,
            'show_in_footer' => true,
            'footer_label' => 'Privacy',
            'sort_order' => 10,
        ]);

        config(['app.privacy_contact_email' => 'privacy@example.test']);

        $this->get('/privacy')
            ->assertOk()
            ->assertSee('Custom Privacy')
            ->assertSee('Database Privacy')
            ->assertSee('privacy@example.test');
    }

    public function test_generic_slug_page_is_public_when_published(): void
    {
        StaticPage::query()->create([
            'slug' => 'about-us',
            'title' => 'About Us',
            'body_markdown' => 'A footer-linked static page.',
            'variables' => json_encode([], JSON_THROW_ON_ERROR),
            'is_published' => true,
            'show_in_footer' => true,
            'footer_label' => 'About',
            'sort_order' => 30,
        ]);

        $this->get('/page/about-us')
            ->assertOk()
            ->assertSee('About Us')
            ->assertSee('A footer-linked static page.');
    }

    public function test_footer_includes_legal_links(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.route('privacy').'"', false)
            ->assertSee('href="'.route('terms').'"', false);
    }

    public function test_default_legal_pages_use_a_fixed_revision_date(): void
    {
        // Travel a year forward: a fixed revision date must not drift to "today".
        $this->travelTo(now()->addYear());

        $this->get('/privacy')
            ->assertOk()
            ->assertSee('Last updated: '.DefaultStaticPages::REVISION_DATE)
            ->assertDontSee(now()->format('F j, Y'));
    }

    public function test_unpublished_static_page_returns_404_rather_than_built_in_fallback(): void
    {
        // A deliberately unpublished privacy row must not silently revert to the
        // built-in boilerplate — the Published toggle has to be effective.
        StaticPage::query()->create([
            'slug' => 'privacy',
            'title' => 'Draft Privacy',
            'body_markdown' => 'Work in progress.',
            'variables' => json_encode([], JSON_THROW_ON_ERROR),
            'is_published' => false,
            'show_in_footer' => false,
            'footer_label' => null,
            'sort_order' => 10,
        ]);

        $this->get('/privacy')->assertNotFound();
    }

    public function test_seeded_legal_pages_follow_live_contact_config(): void
    {
        // Seed the defaults into the database, mirroring the admin "seed defaults"
        // action, so the rendered page comes from a stored row.
        foreach (DefaultStaticPages::all() as $page) {
            StaticPage::query()->create(array_merge($page, [
                'variables' => json_encode($page['variables'], JSON_THROW_ON_ERROR),
            ]));
        }

        // Changing the configured contact must flow through to the seeded page; the
        // address is not frozen into the stored variables.
        config(['app.privacy_contact_email' => 'newcontact@example.test']);

        $this->get('/privacy')
            ->assertOk()
            ->assertSee('newcontact@example.test');
    }

    public function test_footer_keeps_legal_links_when_a_custom_footer_page_exists(): void
    {
        StaticPage::query()->create([
            'slug' => 'about-us',
            'title' => 'About Us',
            'body_markdown' => 'Hello.',
            'variables' => json_encode([], JSON_THROW_ON_ERROR),
            'is_published' => true,
            'show_in_footer' => true,
            'footer_label' => 'About',
            'sort_order' => 5,
        ]);

        // Adding a custom footer page must not drop the required legal links.
        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.route('privacy').'"', false)
            ->assertSee('href="'.route('terms').'"', false)
            ->assertSee('href="'.route('pages.show', 'about-us').'"', false);
    }

    public function test_footer_omits_legal_link_when_its_page_is_unpublished(): void
    {
        // Admin unpublishes the privacy page → show() 404s it → the footer must not
        // keep a now-broken default Privacy link. Terms is untouched.
        StaticPage::query()->create([
            'slug' => 'privacy',
            'title' => 'Privacy',
            'body_markdown' => 'Work in progress.',
            'variables' => json_encode([], JSON_THROW_ON_ERROR),
            'is_published' => false,
            'show_in_footer' => false,
            'footer_label' => null,
            'sort_order' => 10,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('href="'.route('privacy').'"', false)
            ->assertSee('href="'.route('terms').'"', false);
    }

    public function test_legacy_contact_variables_are_scrubbed_by_migration(): void
    {
        // Simulate an install seeded before the fix: the privacy row froze the old
        // app_name/contact into its stored variables.
        StaticPage::query()->create([
            'slug' => 'privacy',
            'title' => 'Privacy Policy',
            'body_markdown' => 'Contact {{privacy_contact_email}}.',
            'variables' => json_encode([
                'app_name' => 'Old App',
                'privacy_contact_email' => 'stale@example.test',
                'last_updated' => 'June 1, 2026',
            ], JSON_THROW_ON_ERROR),
            'is_published' => true,
            'show_in_footer' => true,
            'footer_label' => 'Privacy',
            'sort_order' => 10,
        ]);

        $migration = require database_path('migrations/2026_06_29_000000_scrub_legacy_static_page_contact_variables.php');
        $migration->up();

        $variables = json_decode((string) StaticPage::query()->where('slug', 'privacy')->value('variables'), true);
        $this->assertArrayNotHasKey('privacy_contact_email', $variables);
        $this->assertArrayNotHasKey('app_name', $variables);
        // Page-specific keys are preserved.
        $this->assertSame('June 1, 2026', $variables['last_updated']);

        // The live config now flows through to the rendered page.
        config(['app.privacy_contact_email' => 'fresh@example.test']);
        $this->get('/privacy')->assertOk()->assertSee('fresh@example.test');
    }

    public function test_deactivated_user_can_still_reach_legal_pages(): void
    {
        $user = User::factory()->approved()->create(['deactivated_at' => now()]);

        $this->actingAs($user)->get('/privacy')->assertOk()->assertSee('Privacy Policy');
        $this->actingAs($user)->get('/terms')->assertOk()->assertSee('Terms of Service');

        // A gated route still bounces them to the reactivate page.
        $this->actingAs($user)->get('/')->assertRedirect(route('account.deactivated'));
    }
}
