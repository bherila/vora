<?php

namespace App\Support;

class WebPushEndpoint
{
    public static function validationError(string $endpoint): ?string
    {
        $parsed = parse_url($endpoint);
        if (($parsed['scheme'] ?? null) !== 'https') {
            return 'Push subscription endpoints must use HTTPS.';
        }

        $host = strtolower(rtrim((string) ($parsed['host'] ?? ''), '.'));
        if ($host === '') {
            return 'Push subscription endpoint host is not allowed.';
        }

        $literalIp = filter_var($host, FILTER_VALIDATE_IP);
        if ($literalIp !== false) {
            return self::isPublicIp($literalIp) ? null : 'Push subscription endpoint host is not allowed.';
        }

        if (! self::isAllowedHostname($host)) {
            return 'Push subscription endpoint host is not allowed.';
        }

        $addresses = self::resolveHost($host);
        if ($addresses === []) {
            return 'Push subscription endpoint host could not be verified.';
        }

        foreach ($addresses as $address) {
            if (! self::isPublicIp($address)) {
                return 'Push subscription endpoint host is not allowed.';
            }
        }

        return null;
    }

    private static function isAllowedHostname(string $host): bool
    {
        if ($host === 'localhost'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.localhost')) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /**
     * @return list<string>
     */
    private static function resolveHost(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $addresses[] = $record['ip'];
            }

            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
