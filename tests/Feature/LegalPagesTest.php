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

    public function test_deactivated_user_can_still_reach_legal_pages(): void
    {
        $user = User::factory()->approved()->create(['deactivated_at' => now()]);

        $this->actingAs($user)->get('/privacy')->assertOk()->assertSee('Privacy Policy');
        $this->actingAs($user)->get('/terms')->assertOk()->assertSee('Terms of Service');

        // A gated route still bounces them to the reactivate page.
        $this->actingAs($user)->get('/')->assertRedirect(route('account.deactivated'));
    }
}
