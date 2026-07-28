<?php

namespace App\Support;

use App\Models\Character;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resolves the signed-in user's server-side authoring identity.
 *
 * This state is deliberately limited to write-surface defaults. Privacy and
 * follow-graph decisions must never consult it.
 */
class ActiveIdentity
{
    public const SESSION_KEY = 'active_character_id';

    public function id(Request $request, User $user): ?int
    {
        $value = $request->session()->get(self::SESSION_KEY);
        if ($value === null) {
            return null;
        }

        if (! is_int($value)
            || ! Character::query()
                ->whereKey($value)
                ->where('user_id', $user->id)
                ->exists()) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $value;
    }

    public function set(Request $request, ?int $characterId): void
    {
        if ($characterId === null) {
            $request->session()->forget(self::SESSION_KEY);

            return;
        }

        $request->session()->put(self::SESSION_KEY, $characterId);
    }
}
