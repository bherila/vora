<?php

namespace App\Enums;

/**
 * Who may view a piece of content. Reusable across media and any future
 * user-owned content (e.g. Stories) via the HasVisibility trait.
 */
enum Visibility: string
{
    /** Any signed-in user can discover and view it. */
    case Users = 'users';

    /** Hidden from discovery; only reachable by someone who has the direct link. */
    case Unlisted = 'unlisted';

    public function label(): string
    {
        return match ($this) {
            self::Users => 'Any user',
            self::Unlisted => 'Only people with the link',
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
