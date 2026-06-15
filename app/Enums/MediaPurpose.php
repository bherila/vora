<?php

namespace App\Enums;

/**
 * Where an uploaded media row is used. Gallery media appears in user-facing
 * media grids; profile pictures are separate profile assets that still reuse
 * the same direct-to-R2 upload and moderation pipeline.
 */
enum MediaPurpose: string
{
    case Gallery = 'gallery';
    case ProfilePicture = 'profile_picture';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
