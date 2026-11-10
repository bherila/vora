<?php

namespace App\Enums;

/**
 * Who may see one level (country or city) of an account's location.
 *
 * The tiers nest: Mutuals ⊂ Followers ⊂ Everyone, and Nobody admits no one.
 * Because a city also names its country, the city tier may never be wider than
 * the country tier; {@see self::rank()} lets validation enforce that.
 */
enum LocationAudience: string
{
    case Nobody = 'nobody';
    case Mutuals = 'mutuals';
    case Followers = 'followers';
    case Everyone = 'everyone';

    public function rank(): int
    {
        return match ($this) {
            self::Nobody => 0,
            self::Mutuals => 1,
            self::Followers => 2,
            self::Everyone => 3,
        };
    }

    /**
     * Whether a viewer with the given follow edges to the owner is admitted.
     *
     * @param  bool  $viewerFollowsOwner  accepted account follow, viewer → owner
     * @param  bool  $ownerFollowsViewer  accepted account follow, owner → viewer
     */
    public function admits(bool $viewerFollowsOwner, bool $ownerFollowsViewer): bool
    {
        return match ($this) {
            self::Nobody => false,
            self::Mutuals => $viewerFollowsOwner && $ownerFollowsViewer,
            self::Followers => $viewerFollowsOwner,
            self::Everyone => true,
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
