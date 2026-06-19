<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Thin typed accessor over the {@see Setting} key/value table with per-key cache
 * memoization. Admin-controlled runtime flags (e.g. whether public signups are
 * open) live here rather than in static config so they can change without a deploy.
 */
class SettingsService
{
    public const PUBLIC_SIGNUPS_ENABLED = 'public_signups_enabled';

    public const DEFAULT_NEW_USER_INVITES = 'default_new_user_invites';

    public const DEFAULT_NEW_USER_INVITE_EXPIRY_DAYS = 'default_new_user_invite_expiry_days';

    private const CACHE_PREFIX = 'setting:';

    public function publicSignupsEnabled(): bool
    {
        return $this->getBool(self::PUBLIC_SIGNUPS_ENABLED, true);
    }

    public function defaultNewUserInvites(): int
    {
        return $this->getInt(self::DEFAULT_NEW_USER_INVITES, 0);
    }

    public function defaultNewUserInviteExpiryDays(): ?int
    {
        $value = $this->get(self::DEFAULT_NEW_USER_INVITE_EXPIRY_DAYS);

        return $value === null || $value === '' ? null : (int) $value;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return Cache::rememberForever(self::CACHE_PREFIX.$key, function () use ($key, $default): ?string {
            return Setting::query()->where('key', $key)->value('value') ?? $default;
        });
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default ? '1' : '0');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    public function set(string $key, string|int|bool|null $value): void
    {
        $stored = match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            default => $value,
        };

        Setting::query()->updateOrCreate(['key' => $key], ['value' => $stored]);
        Cache::forget(self::CACHE_PREFIX.$key);
    }
}
