<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Deployment capabilities are independent of per-account storage entitlements. */
final class InstanceFeatures
{
    public static function enabled(string $module): bool
    {
        return config('instance.modules.'.$module, false) === true;
    }

    public static function homePath(): string
    {
        return self::enabled('events') ? '/events' : (self::enabled('community') ? '/feed' : '/user/settings');
    }

    /** @return list<string> */
    public static function routeModules(string $uri): array
    {
        $patterns = [
            'events' => ['events', 'events/*', 'attendees', 'attendees/*', 'api/events', 'api/events/*', 'api/admin/events', 'api/admin/events/*'],
            'library' => ['library', 'api/library', 'api/library/*'],
            'favorites' => ['api/favorites', 'api/users/*/favorites'],
            'personas' => ['characters', 'characters/*', 'personas/*', 'c/*', 'api/c/*', 'api/explore/personas', 'api/characters', 'api/characters/*', 'api/identity', 'api/library/personas'],
            'stories' => ['stories', 's/*', 'api/c/*/stories', 'api/users/*/stories', 'api/explore/stories', 'api/stories', 'api/stories/*', 'api/authorship-invites', 'api/authorship-invites/*', 'admin/stories', 'api/admin/stories', 'api/admin/stories/*'],
            'chat' => ['messages', 'messages/*', 'api/chat', 'api/chat/*'],
            'community' => ['feed', 'media', 'dashboard', 'api/c/*/posts', 'api/users/*/posts', 'api/feed', 'explore', 'api/explore', 'api/explore/*', 'me/activity', 'api/me/activity', 'api/me/activity/*', 'api/side-rail', 'api/side-rail/*', 'api/onboarding/*', 'api/posts', 'api/posts/*', 'p/*', 'api/media/by-ulid/*/discussion', 'admin/posts', 'api/admin/posts', 'api/admin/posts/*', 'api/admin/post-comments', 'api/admin/post-comments/*'],
            'profiles' => ['api/media', 'api/media/bulk', 'me', 'users', 'users/*', 'api/users', 'api/users/*'],
            'interests' => ['interests/*', 'api/interests', 'api/interests/*', 'admin/interests', 'api/admin/interests', 'api/admin/interests/*', 'api/admin/interest-requests', 'api/admin/interest-requests/*'],
        ];

        return array_keys(array_filter($patterns, static fn (array $matches): bool => Str::is($matches, $uri)));
    }
}
