<?php

namespace Tests\Feature\Mail;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Tests\TestCase;

class BrevoMailerConfigTest extends TestCase
{
    public function test_brevo_mailer_sends_through_the_brevo_api(): void
    {
        config(['services.brevo.dsn' => 'brevo+api://xkeysib-test-key@default']);

        $this->assertInstanceOf(BrevoApiTransport::class, Mail::mailer('brevo')->getSymfonyTransport());
    }

    public function test_hybrid_mailer_tries_brevo_then_falls_back_to_smtp(): void
    {
        config(['services.brevo.dsn' => 'brevo+api://xkeysib-test-key@default']);

        $transport = Mail::mailer('hybrid')->getSymfonyTransport();

        $this->assertInstanceOf(FailoverTransport::class, $transport);
        $this->assertStringStartsWith('failover(brevo+api://api.brevo.com smtp://', (string) $transport);
    }
}
