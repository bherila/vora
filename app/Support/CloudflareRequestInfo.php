<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Extracts the visitor's IP and geo from the Cloudflare-injected request headers.
 *
 * The app sits behind Cloudflare in production. `CF-Connecting-IP` and
 * `CF-IPCountry` are always present there; the finer-grained geo headers
 * (city/region/postal/lat-long) appear only when a Cloudflare managed transform
 * is enabled, so we capture whatever is present and drop the rest.
 *
 * TrustProxies is not configured, so we read these headers directly. That is fine
 * for the display/logging use here as long as the origin only accepts Cloudflare
 * traffic; a direct-to-origin request could spoof them, so this data is advisory.
 */
class CloudflareRequestInfo
{
    /**
     * @return array{ip: ?string, geo: array<string, string>}
     */
    public static function from(Request $request): array
    {
        $ip = $request->header('CF-Connecting-IP') ?: $request->ip();

        $geo = array_filter([
            'country' => $request->header('CF-IPCountry'),
            'city' => $request->header('CF-IPCity'),
            'region' => $request->header('CF-IPRegion'),
            'postal' => $request->header('CF-IPPostalCode'),
            'latitude' => $request->header('CF-IPLatitude'),
            'longitude' => $request->header('CF-IPLongitude'),
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        return ['ip' => $ip, 'geo' => $geo];
    }
}
