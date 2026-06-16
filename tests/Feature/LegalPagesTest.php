<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
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
            ->assertSee('Acceptable use');
    }

    public function test_footer_includes_legal_links(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.route('privacy').'"', false)
            ->assertSee('href="'.route('terms').'"', false);
    }
}
