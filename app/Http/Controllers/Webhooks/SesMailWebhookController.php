<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Mail\MailDeliveryLog;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * SES delivery events published to SNS (configuration-set event publishing or
 * identity notifications). Every message must carry a valid SNS signature and
 * come from the one configured topic.
 */
class SesMailWebhookController extends Controller
{
    private const SUBSCRIBE_URL = '/^https:\/\/sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?\//';

    /** AWS's own certificate path; anything looser would let unsigned requests trigger fetches. */
    private const SIGNING_CERT_URL = '/^https:\/\/sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?\/SimpleNotificationService-[0-9a-f]+\.pem$/';

    private const MESSAGE_TYPES = ['Notification', 'SubscriptionConfirmation', 'UnsubscribeConfirmation'];

    public function __invoke(Request $request, MailDeliveryLog $log, MessageValidator $validator): Response
    {
        $topic = (string) config('services.ses.notification_topic_arn');
        abort_if($topic === '', 404);
        // Cheap shape checks first: an unsigned request must not make this app
        // fetch a certificate (each fetch can hold a worker for seconds).
        $type = $request->header('x-amz-sns-message-type');
        $certUrl = json_decode($request->getContent(), true)['SigningCertURL'] ?? null;
        abort_unless(in_array($type, self::MESSAGE_TYPES, true) && is_string($certUrl) && preg_match(self::SIGNING_CERT_URL, $certUrl) === 1, 403);
        try {
            $message = Message::fromJsonString($request->getContent());
            $validator->validate($message);
        } catch (Throwable) {
            abort(403);
        }
        abort_unless(hash_equals($topic, (string) $message['TopicArn']), 403);

        if ($message['Type'] === 'SubscriptionConfirmation') {
            $url = (string) $message['SubscribeURL'];
            abort_unless(preg_match(self::SUBSCRIBE_URL, $url) === 1, 403);
            Http::timeout(10)->get($url)->throw();

            return response()->noContent();
        }
        if ($message['Type'] === 'Notification') {
            $event = json_decode((string) $message['Message'], true);
            if (is_array($event)) {
                $this->applyEvent($event, $log);
            }
        }

        return response()->noContent();
    }

    /** @param array<string, mixed> $event */
    private function applyEvent(array $event, MailDeliveryLog $log): void
    {
        $messageId = $event['mail']['messageId'] ?? null;
        if (! is_string($messageId)) {
            return;
        }
        $type = $event['eventType'] ?? $event['notificationType'] ?? null;
        [$status, $detail, $recipients, $reason] = match ($type) {
            'Send' => ['sent', null, null, null],
            'Delivery' => ['delivered', $event['delivery'] ?? [], $event['delivery']['recipients'] ?? [], null],
            'Bounce' => [
                ($event['bounce']['bounceType'] ?? '') === 'Permanent' ? 'bounced' : 'deferred',
                $event['bounce'] ?? [],
                array_column($event['bounce']['bouncedRecipients'] ?? [], 'emailAddress'),
                $event['bounce']['bouncedRecipients'][0]['diagnosticCode'] ?? $event['bounce']['bounceSubType'] ?? null,
            ],
            'Complaint' => ['complained', $event['complaint'] ?? [], array_column($event['complaint']['complainedRecipients'] ?? [], 'emailAddress'), $event['complaint']['complaintFeedbackType'] ?? null],
            'DeliveryDelay' => ['deferred', $event['deliveryDelay'] ?? [], array_column($event['deliveryDelay']['delayedRecipients'] ?? [], 'emailAddress'), $event['deliveryDelay']['delayType'] ?? null],
            'Reject' => ['failed', $event['reject'] ?? [], null, $event['reject']['reason'] ?? null],
            'Rendering Failure' => ['failed', $event['failure'] ?? [], null, $event['failure']['errorMessage'] ?? null],
            default => [null, null, null, null],
        };
        if ($status === null) {
            return;
        }
        $timestamp = is_array($detail) ? ($detail['timestamp'] ?? null) : null;
        $at = is_string($timestamp) ? CarbonImmutable::parse($timestamp) : CarbonImmutable::now();
        $reason = is_string($reason) ? $reason : null;
        if ($recipients === null) {
            $log->apply($messageId, null, $status, $reason, $at);

            return;
        }
        foreach ($recipients as $recipient) {
            if (is_string($recipient)) {
                $log->apply($messageId, $recipient, $status, $reason, $at);
            }
        }
    }
}
