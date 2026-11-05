<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

class UpdateLastLoginDate
{
    public function handle(Login $event): void
    {
        // Embedded Auth Manager emits the same event for its separate directory.
        if ($event->guard !== 'web' || ! $event->user instanceof User) {
            return;
        }

        $user = $event->user;
        $usesTimestamps = $user->timestamps;

        $user->timestamps = false;

        try {
            $user->forceFill([
                'last_login_at' => now(),
            ])->saveQuietly();
        } finally {
            $user->timestamps = $usesTimestamps;
        }
    }
}
