<?php

namespace App\Enums;

/**
 * The authoring format of a story. Long-form stories are a single markdown
 * body; choose-your-own-adventure stories are a graph of markdown passages
 * (nodes) connected by reader choices (edges).
 */
enum StoryType: string
{
    case LongForm = 'long_form';
    case Cyoa = 'cyoa';

    public function label(): string
    {
        return match ($this) {
            self::LongForm => 'Long form',
            self::Cyoa => 'Choose your own adventure',
        };
    }

    public function isCyoa(): bool
    {
        return $this === self::Cyoa;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
