<?php

namespace Tests\Feature\Mail;

use App\Models\MailDelivery;
use App\Providers\AppServiceProvider;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

class SesMailWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOPIC = 'arn:aws:sns:us-east-1:123456789012:ses-delivery';

    private const CERT_URL = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-0123456789abcdef.pem';

    private OpenSSLAsymmetricKey $key;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ses.notification_topic_arn' => self::TOPIC]);
        // A throwaway signing pair stands in for AWS's certificate; the real
        // validator still checks the URL, signature and string-to-sign.
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $this->key), null, $this->key, 1);
        openssl_x509_export($certificate, $pem);
        $this->app->bind(MessageValidator::class, fn (): MessageValidator => new MessageValidator(
            fn (string $url): string|false => $url === self::CERT_URL ? $pem : false,
        ));
    }

    /** @param array<string, mixed> $fields */
    private function sns(array $fields, bool $sign = true): TestResponse
    {
        $message = ['MessageId' => 'sns-1', 'TopicArn' => self::TOPIC, 'Timestamp' => '2026-10-26T18:00:00.000Z',
            'SignatureVersion' => '1', 'SigningCertURL' => self::CERT_URL, 'Signature' => '', ...$fields];
        if ($sign) {
            openssl_sign((new MessageValidator)->getStringToSign(new Message($message)), $signature, $this->key, OPENSSL_ALGO_SHA1);
            $message['Signature'] = base64_encode($signature);
        }

        return $this->call('POST', '/api/webhooks/mail/ses', [], [], [], ['CONTENT_TYPE' => 'text/plain; charset=UTF-8', 'HTTP_X_AMZ_SNS_MESSAGE_TYPE' => $message['Type'] ?? ''], json_encode($message));
    }

    /** @param array<string, mixed> $event */
    private function notification(array $event): TestResponse
    {
        return $this->sns(['Type' => 'Notification', 'Subject' => 'Amazon SES Email Event Notification', 'Message' => json_encode(['mail' => ['messageId' => '0100018f-ses-id'], ...$event])]);
    }

    private function delivery(string $recipient): MailDelivery
    {
        return MailDelivery::create(['mailer' => 'ses', 'provider_message_id' => '0100018f-ses-id', 'recipient' => $recipient,
            'status' => 'sent', 'sent_at' => now()->subMinute(), 'status_at' => now()->subMinute()]);
    }

    public function test_only_signed_messages_from_the_configured_topic_are_accepted(): void
    {
        $this->delivery('guest@example.test');
        $delivered = ['eventType' => 'Delivery', 'delivery' => ['timestamp' => '2026-10-26T18:00:01.000Z', 'recipients' => ['guest@example.test']]];
        $body = json_encode(['mail' => ['messageId' => '0100018f-ses-id'], ...$delivered]);

        $this->sns(['Type' => 'Notification', 'Message' => $body], sign: false)->assertForbidden();
        $this->sns(['Type' => 'Notification', 'Message' => $body, 'TopicArn' => 'arn:aws:sns:us-east-1:999999999999:other'])->assertForbidden();
        $this->sns(['Type' => 'Notification', 'Message' => $body, 'SigningCertURL' => 'https://attacker.example.test/cert.pem'])->assertForbidden();
        $this->assertSame('sent', MailDelivery::sole()->status);

        config(['services.ses.notification_topic_arn' => null]);
        $this->notification($delivered)->assertNotFound();
    }

    public function test_signed_but_malformed_events_are_acknowledged_without_changes(): void
    {
        $delivery = $this->delivery('guest@example.test');
        $this->notification(['eventType' => 'Delivery', 'delivery' => ['timestamp' => 'not a time', 'recipients' => ['guest@example.test']]])->assertNoContent();
        $this->sns(['Type' => 'Notification', 'Message' => 'not json'])->assertNoContent();
        $this->assertSame('sent', $delivery->fresh()->status);
    }

    public function test_unsigned_requests_never_trigger_a_certificate_fetch(): void
    {
        $this->app->bind(MessageValidator::class, fn (): MessageValidator => AppServiceProvider::snsMessageValidator());
        Http::fake();
        foreach (['https://sns.us-east-1.amazonaws.com/random.pem', 'https://sns.us-east-1.amazonaws.com/?x=.pem', 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc.pem?x'] as $url) {
            $this->sns(['Type' => 'Notification', 'Message' => '{}', 'SigningCertURL' => $url], sign: false)->assertForbidden();
        }
        $this->call('POST', '/api/webhooks/mail/ses', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode(['Type' => 'Notification', 'SigningCertURL' => self::CERT_URL]))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_subscription_confirmation_is_fetched_only_from_amazon_sns(): void
    {
        Http::fake(['sns.us-east-1.amazonaws.com/*' => Http::response('<ConfirmSubscriptionResponse/>')]);
        $confirm = 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn='.self::TOPIC.'&Token=abc';
        $this->sns(['Type' => 'SubscriptionConfirmation', 'Token' => 'abc', 'Message' => 'Confirm', 'SubscribeURL' => $confirm])->assertNoContent();
        Http::assertSent(fn ($request) => $request->url() === $confirm);

        $this->sns(['Type' => 'SubscriptionConfirmation', 'Token' => 'abc', 'Message' => 'Confirm', 'SubscribeURL' => 'https://internal.example.test/steal'])->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_delivery_bounce_complaint_and_rejection_events_update_the_right_recipients(): void
    {
        $guest = $this->delivery('guest@example.test');
        $typo = $this->delivery('typo@exmaple.test');

        $this->notification(['eventType' => 'Delivery', 'delivery' => ['timestamp' => '2026-10-26T18:00:01.000Z', 'recipients' => ['guest@example.test']]])->assertNoContent();
        $this->notification(['eventType' => 'Bounce', 'bounce' => ['bounceType' => 'Permanent', 'bounceSubType' => 'General', 'timestamp' => '2026-10-26T18:00:02.000Z',
            'bouncedRecipients' => [['emailAddress' => 'Typo@Exmaple.test', 'diagnosticCode' => 'smtp; 550 5.1.1 user unknown']]]])->assertNoContent();
        $this->assertSame('delivered', $guest->fresh()->status);
        $this->assertSame('bounced', $typo->fresh()->status);
        $this->assertSame('smtp; 550 5.1.1 user unknown', $typo->fresh()->status_reason);

        // Identity notifications use notificationType instead of eventType.
        $this->notification(['notificationType' => 'Complaint', 'complaint' => ['timestamp' => '2026-10-26T18:05:00.000Z', 'complaintFeedbackType' => 'abuse',
            'complainedRecipients' => [['emailAddress' => 'guest@example.test']]]])->assertNoContent();
        $this->assertSame('complained', $guest->fresh()->status);

        $rejected = MailDelivery::create(['mailer' => 'ses', 'provider_message_id' => 'rejected-id', 'recipient' => 'a@example.test', 'status' => 'sent', 'sent_at' => now(), 'status_at' => now()]);
        $this->sns(['Type' => 'Notification', 'Message' => json_encode(['eventType' => 'Reject', 'mail' => ['messageId' => 'rejected-id'], 'reject' => ['reason' => 'Bad content']])])->assertNoContent();
        $this->assertSame('failed', $rejected->fresh()->status);
        $this->assertSame('Bad content', $rejected->fresh()->status_reason);
    }
}
