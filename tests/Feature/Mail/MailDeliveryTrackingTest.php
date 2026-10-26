<?php

namespace Tests\Feature\Mail;

use App\Models\MailDelivery;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class MailDeliveryTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const BREVO_ID = '<202610261827.12345678901@smtp-relay.mailin.fr>';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'services.brevo.webhook_secret' => 'brevo-secret']);
    }

    private function delivery(array $attributes = []): MailDelivery
    {
        return MailDelivery::create([
            'mailer' => 'hybrid', 'provider_message_id' => '202610261827.12345678901@smtp-relay.mailin.fr',
            'recipient' => 'guest@example.test', 'status' => 'sent', 'sent_at' => now()->subMinute(), 'status_at' => now()->subMinute(),
            ...$attributes,
        ]);
    }

    private function brevo(array $payload, string $secret = 'brevo-secret'): TestResponse
    {
        return $this->withToken($secret)->postJson('/api/webhooks/mail/brevo', $payload);
    }

    public function test_sent_notifications_are_recorded_per_recipient_without_subject_or_body(): void
    {
        $user = User::factory()->create(['email' => 'guest@example.test']);
        $user->notify(new ResetPassword('reset-token-secret'));

        $delivery = MailDelivery::sole();
        $this->assertSame('ResetPassword', $delivery->type);
        $this->assertSame($user->id, $delivery->user_id);
        $this->assertSame('guest@example.test', $delivery->recipient);
        $this->assertSame('array', $delivery->mailer);
        $this->assertSame('sent', $delivery->status);
        $this->assertStringNotContainsString('<', $delivery->provider_message_id);
        $this->assertStringNotContainsString('reset-token-secret', json_encode($delivery->getAttributes()));
    }

    public function test_brevo_api_sends_record_brevos_message_id(): void
    {
        $transport = new BrevoApiTransport('xkeysib-test', new MockHttpClient(new MockResponse(json_encode(['messageId' => self::BREVO_ID]), ['http_code' => 201])));
        Mail::extend('brevo-test', fn () => $transport);
        config(['mail.mailers.brevo-test' => ['transport' => 'brevo-test'], 'mail.default' => 'brevo-test']);
        Mail::raw('Hello', fn ($message) => $message->to('Guest@Example.test')->cc('second@example.test')->subject('Hi'));

        $this->assertSame(['guest@example.test', 'second@example.test'], MailDelivery::orderBy('recipient')->pluck('recipient')->all());
        $this->assertSame(['202610261827.12345678901@smtp-relay.mailin.fr'], MailDelivery::distinct()->pluck('provider_message_id')->all());
    }

    public function test_ses_sends_record_the_ses_message_id_header(): void
    {
        Mail::to('guest@example.test')->send(new class extends Mailable
        {
            public function build(): static
            {
                // Laravel's SES transports add this header with SES's MessageId.
                return $this->subject('Hi')->html('<p>Hi</p>')
                    ->withSymfonyMessage(fn (Email $email) => $email->getHeaders()->addTextHeader('X-SES-Message-ID', '0100018f-ses-id'));
            }
        });

        $this->assertSame('0100018f-ses-id', MailDelivery::sole()->provider_message_id);
    }

    public function test_one_recipient_failing_spares_the_others(): void
    {
        MailDelivery::creating(function (MailDelivery $delivery): void {
            if ($delivery->recipient === 'broken@example.test') {
                throw new \RuntimeException('Simulated insert failure');
            }
        });
        Mail::raw('Hello', fn ($message) => $message->to('broken@example.test')->cc('guest@example.test')->bcc('third@example.test')->subject('Hi'));

        $this->assertSame(['guest@example.test', 'third@example.test'], MailDelivery::orderBy('recipient')->pluck('recipient')->all());
    }

    public function test_bookkeeping_failure_never_fails_the_send(): void
    {
        Schema::drop('mail_deliveries');
        $this->withoutExceptionHandling();
        Mail::raw('Hello', fn ($message) => $message->to('guest@example.test')->subject('Hi'));
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_brevo_webhook_requires_its_secret_and_is_off_until_configured(): void
    {
        config(['services.brevo.webhook_secret' => null]);
        $this->brevo(['event' => 'delivered'])->assertNotFound();
        config(['services.brevo.webhook_secret' => 'brevo-secret']);
        $this->brevo(['event' => 'delivered'], 'wrong')->assertUnauthorized();
        $this->postJson('/api/webhooks/mail/brevo', ['event' => 'delivered'])->assertUnauthorized();
        $this->withBasicAuth('brevo', 'brevo-secret')->postJson('/api/webhooks/mail/brevo', ['event' => 'delivered'])->assertNoContent();
    }

    public function test_brevo_events_move_status_forward_and_ignore_other_apps_and_engagement(): void
    {
        $delivery = $this->delivery();
        $other = $this->delivery(['recipient' => 'other@example.test']);
        $at = now()->timestamp;
        $this->brevo([
            ['event' => 'delivered', 'email' => 'Guest@Example.test', 'message-id' => self::BREVO_ID, 'ts_event' => $at],
            ['event' => 'opened', 'email' => 'other@example.test', 'message-id' => self::BREVO_ID, 'ts_event' => $at],
            ['event' => 'delivered', 'email' => 'someone@another-app.test', 'message-id' => '<unknown@smtp-relay.mailin.fr>', 'ts_event' => $at],
        ])->assertNoContent();
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame('sent', $other->fresh()->status);
        $this->assertSame(2, MailDelivery::count());

        // A late deferral never hides delivery; a later hard bounce does.
        $this->brevo(['event' => 'deferred', 'email' => 'guest@example.test', 'message-id' => self::BREVO_ID, 'ts_event' => $at + 5])->assertNoContent();
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->brevo(['event' => 'hard_bounce', 'email' => 'guest@example.test', 'message-id' => self::BREVO_ID, 'ts_event' => $at + 10, 'reason' => '550 5.1.1 User unknown'])->assertNoContent();
        $this->assertSame('bounced', $delivery->fresh()->status);
        $this->assertSame('550 5.1.1 User unknown', $delivery->fresh()->status_reason);
    }

    public function test_admins_can_filter_recent_deliveries_with_a_seven_day_summary(): void
    {
        $admin = User::factory()->admin()->approved()->create();
        $member = User::factory()->approved()->create();
        $this->delivery(['status' => 'delivered', 'recipient' => 'a@example.test', 'type' => 'VerifyEmail']);
        $this->delivery(['status' => 'bounced', 'recipient' => 'typo@exmaple.test', 'status_reason' => 'User unknown']);
        $this->delivery(['status' => 'delivered', 'recipient' => 'old@example.test', 'sent_at' => now()->subDays(8)]);

        $this->actingAs($member)->getJson('/api/admin/mail-deliveries')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/admin/mail-deliveries')->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('summary.delivered', 1)->assertJsonPath('summary.bounced', 1)
            ->assertJsonPath('reports.brevo', true)->assertJsonPath('reports.ses', false);
        $this->getJson('/api/admin/mail-deliveries?status=bounced')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.recipient', 'typo@exmaple.test')->assertJsonPath('data.0.status_reason', 'User unknown');
        $this->getJson('/api/admin/mail-deliveries?search=EXMAPLE')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/mail-deliveries?search=100%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/mail-deliveries?status=opened')->assertUnprocessable();
        $this->get('/admin/mail-deliveries')->assertOk()->assertSee('admin-mail-deliveries');
    }

    public function test_admin_search_treats_wildcards_literally_on_every_database(): void
    {
        $admin = User::factory()->admin()->approved()->create();
        $this->delivery(['recipient' => 'a_b@example.test']);
        $this->delivery(['recipient' => 'axb@example.test']);
        $this->delivery(['recipient' => 'zero0@example.test']);
        $this->delivery(['recipient' => 'pct%sign@example.test']);
        $search = fn (string $term): array => array_column($this->actingAs($admin)->getJson('/api/admin/mail-deliveries?search='.rawurlencode($term))->assertOk()->json('data'), 'recipient');

        $this->assertSame(['a_b@example.test'], $search('a_b'));
        $this->assertSame(['pct%sign@example.test'], $search('%sign'));
        $this->assertSame(['zero0@example.test'], $search('0'));
        $this->assertSame([], $search('!'));
    }

    public function test_records_older_than_ninety_days_are_pruned(): void
    {
        $old = $this->delivery(['sent_at' => now()->subDays(91)]);
        $recent = $this->delivery(['sent_at' => now()->subDays(89)]);
        $this->artisan('model:prune', ['--model' => [MailDelivery::class]])->assertSuccessful();
        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }
}
