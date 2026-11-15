<?php

namespace App\Support;

/**
 * Accepts a push subscription endpoint only on a known browser push service
 * (config `webpush.endpoint_hosts`). Matching a fixed list needs no DNS lookup
 * and keeps the delivery job from sending requests to arbitrary hosts.
 */
class WebPushEndpoint
{
    public static function validationError(string $endpoint): ?string
    {
        $parsed = parse_url($endpoint);
        if (($parsed['scheme'] ?? null) !== 'https') {
            return 'Push subscription endpoints must use HTTPS.';
        }

        if (isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['port'])) {
            return 'Push subscription endpoint host is not allowed.';
        }

        $host = strtolower(rtrim((string) ($parsed['host'] ?? ''), '.'));
        if ($host === '' || ! self::isKnownPushService($host)) {
            return 'Push subscription endpoint host is not allowed.';
        }

        return null;
    }

    public static function isKnownPushService(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return false;
        }

        foreach ((array) config('webpush.endpoint_hosts', []) as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            if ($allowed === '') {
                continue;
            }

            if (str_starts_with($allowed, '.')
                ? str_ends_with($host, $allowed) && strlen($host) > strlen($allowed)
                : $host === $allowed) {
                return true;
            }
        }

        return false;
    }
}
