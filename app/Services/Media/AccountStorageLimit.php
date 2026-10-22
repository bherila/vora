<?php

namespace App\Services\Media;

use App\Models\User;
use App\Services\SettingsService;

class AccountStorageLimit
{
    public function __construct(private readonly SettingsService $settings) {}

    public function forUser(User $user): ?int
    {
        return $this->resolve($user, $this->settings->defaultStorageLimitBytes());
    }

    /** Resolve an already-loaded default for account listings without N+1 reads. */
    public function resolve(User $user, ?int $default): ?int
    {
        return $user->storage_unlimited ? null : ($user->storage_limit_bytes ?? $default);
    }
}
