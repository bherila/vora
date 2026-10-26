<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Mail\MailDeliveryLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Brevo transactional webhook. The Brevo account may be shared with other apps,
 * so events for messages this app did not send are accepted and ignored.
 */
class BrevoMailWebhookController extends Controller
{
    private const MAX_EVENTS = 500;

    /** Opens and clicks are deliberately not tracked. */
    private const STATUS = [
        'request' => 'sent',
        'deferred' => 'deferred',
        'soft_bounce' => 'deferred',
        'delivered' => 'delivered',
        'hard_bounce' => 'bounced',
        'invalid_email' => 'bounced',
        'blocked' => 'blocked',
        'error' => 'failed',
        'spam' => 'complained',
    ];

    public function __invoke(Request $request, MailDeliveryLog $log): Response
    {
        $secret = (string) config('services.brevo.webhook_secret');
        abort_if($secret === '', 404);
        abort_unless($this->authorized($request, $secret), 401);

        $payload = $request->json()->all();
        $events = array_is_list($payload) ? $payload : [$payload];
        foreach (array_slice($events, 0, self::MAX_EVENTS) as $event) {
            if (! is_array($event)) {
                continue;
            }
            $status = self::STATUS[$event['event'] ?? ''] ?? null;
            $messageId = $event['message-id'] ?? null;
            $email = $event['email'] ?? null;
            if ($status === null || ! is_string($messageId) || ! is_string($email)) {
                continue;
            }
            $at = is_numeric($event['ts_event'] ?? null) ? CarbonImmutable::createFromTimestampUTC((int) $event['ts_event']) : CarbonImmutable::now();
            $log->apply($messageId, $email, $status, is_string($event['reason'] ?? null) ? $event['reason'] : null, $at);
        }

        return response()->noContent();
    }

    /** Brevo can send the secret as a bearer token or as the Basic auth password. */
    private function authorized(Request $request, string $secret): bool
    {
        $token = $request->bearerToken() ?? $request->getPassword();

        return is_string($token) && hash_equals($secret, $token);
    }
}
