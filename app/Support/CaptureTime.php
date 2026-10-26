<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Client-reported capture times are untrusted ordering hints, never facts:
 * anything malformed or implausible is dropped instead of failing an upload.
 */
final class CaptureTime
{
    private const EARLIEST = '1990-01-01T00:00:00Z';

    private const PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?<zone>Z|[+-]\d{2}:\d{2})?$/';

    /**
     * EXIF wall-clock times often carry no offset; read those in the Event's
     * time zone so one gathering sorts consistently whatever each phone is set to.
     */
    public static function parse(?string $value, string $fallbackTimezone): ?CarbonImmutable
    {
        if ($value === null || preg_match(self::PATTERN, $value, $match) !== 1) {
            return null;
        }
        try {
            $time = isset($match['zone'])
                ? CarbonImmutable::parse($value)
                : CarbonImmutable::parse($value, $fallbackTimezone);
        } catch (Throwable) {
            return null;
        }
        // Carbon rolls impossible dates (February 30) forward; reject them instead.
        if ($time->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
            return null;
        }
        $time = $time->utc();

        return $time->lt(CarbonImmutable::parse(self::EARLIEST)) || $time->gt(CarbonImmutable::now()->addDay()) ? null : $time;
    }
}
