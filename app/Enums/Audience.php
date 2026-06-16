<?php

namespace App\Enums;

use App\Traits\HasPrivacyPolicy;

/**
 * Who may view a piece of user-owned content. The authorization gate, shared
 * across all content types via {@see HasPrivacyPolicy}.
 *
 * This is deliberately separate from discoverability (the `discoverable` flag):
 * the audience decides *who* may access, while discoverability decides whether
 * the item is listed on surfaces or reachable only by its direct link. A share
 * link never escalates access beyond the audience tier — see HasPrivacyPolicy.
 */
enum Audience: string
{
    /** Any signed-in, approved user. */
    case Everyone = 'everyone';

    /** Accounts that follow the owner (accepted follow). */
    case Followers = 'followers';

    /** Accounts the owner follows back — a follow in both directions. */
    case Mutuals = 'mutuals';

    /** An explicit per-item allowlist of users. */
    case SpecificPeople = 'specific';

    public function label(): string
    {
        return match ($this) {
            self::Everyone => 'Everyone',
            self::Followers => 'Followers',
            self::Mutuals => 'Mutuals (people you follow back)',
            self::SpecificPeople => 'Only specific people',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
