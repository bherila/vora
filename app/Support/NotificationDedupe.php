<?php

namespace App\Support;

use DateInterval;
use Illuminate\Support\Facades\Cache;

/**
 * Lets one actor notify a recipient about one item at most once per window,
 * so removing and re-adding a reaction or favorite (or a burst of comments)
 * does not resend the same notification. The content itself still saves.
 */
final class NotificationDedupe
{
    public static function firstWithin(string $kind, string $itemKey, int $actorId, DateInterval $window): bool
    {
        return Cache::add('notification-dedupe:'.$kind.':'.$itemKey.':'.$actorId, true, $window);
    }
}
