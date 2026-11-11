<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;

/** Trusted operator designation, never an outage-based fallback or an email match. */
final class EmergencyAccess
{
    public const PENDING = 'emergency_access.pending';

    public const SESSION = 'emergency_access.session';

    public function enabled(): bool
    {
        return SignIn::usesProvider() && config('auth.emergency_access.enabled') === true && $this->ids() !== [];
    }

    public function eligible(User $user): ?User
    {
        if (! $this->enabled() || ! in_array((string) $user->getKey(), $this->ids(), true)) {
            return null;
        }
        $stored = User::query()->find($user->getKey());

        return $stored !== null && $stored->isAdmin() && $stored->isApproved() && $stored->hasVerifiedEmail()
            && $stored->canLogin() && ! $stored->isBanned() && ! $stored->isDeactivated()
            && $stored->oauth_provider === null && $stored->oauth_subject === null ? $stored : null;
    }

    /** @return array{id: string, credential: string} */
    public function proof(User $user): array
    {
        return ['id' => (string) $user->getKey(), 'credential' => hash('sha256', (string) $user->getAuthPassword())];
    }

    public function hasSession(Request $request, User $user): bool
    {
        $stored = $this->eligible($user);

        return $stored !== null && $request->session()->get(self::SESSION) === $this->proof($stored);
    }

    /** @return list<string> */
    private function ids(): array
    {
        $ids = config('auth.emergency_access.user_ids', []);
        if (! is_array($ids) || $ids === []) {
            return [];
        }
        $valid = [];
        foreach ($ids as $id) {
            if ((! is_string($id) && ! is_int($id)) || preg_match('/^[1-9][0-9]*$/D', (string) $id) !== 1
                || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                return [];
            }
            $valid[] = (string) $id;
        }

        return array_values(array_unique($valid));
    }
}
