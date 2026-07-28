<?php

namespace App\Policies;

use App\Models\Character;
use App\Models\User;

class CharacterPolicy
{
    /**
     * Whether the viewer may see this persona at all (its public page and the
     * page's content endpoints).
     *
     * Deliberately a pure delegate to {@see Character::isViewableBy()} — the
     * single character-visibility rule (#115) — with no admin before() hook:
     * the rule already grants admins access to any persona whose owner is
     * active and approved, and an inactive owner's personas are unavailable
     * everywhere by design. Adding a bypass here would create a second,
     * drifting copy of the rule.
     */
    public function view(User $user, Character $character): bool
    {
        return $character->isViewableBy($user);
    }
}
