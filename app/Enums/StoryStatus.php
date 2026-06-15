<?php

namespace App\Enums;

/**
 * Publication state of a story. Drafts are visible only to the story's authors;
 * published stories enter discovery/reader surfaces subject to visibility and
 * admin moderation.
 */
enum StoryStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
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
