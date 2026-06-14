<?php

namespace App\Enums;

/**
 * The kind of uploaded media. Drives which storage disk and upload constraints
 * apply, sourced from config/media.php so bucket names stay out of code.
 */
enum MediaType: string
{
    case Photo = 'photo';
    case Video = 'video';

    /** Filesystem disk (config/filesystems.php) this type is stored on. */
    public function disk(): string
    {
        return (string) config('media.disks.'.$this->value);
    }

    /** Maximum allowed upload size in bytes. */
    public function maxBytes(): int
    {
        return (int) config('media.'.$this->value.'.max_bytes');
    }

    /**
     * MIME types accepted for this media type.
     *
     * @return list<string>
     */
    public function allowedMimeTypes(): array
    {
        return (array) config('media.'.$this->value.'.mime_types', []);
    }

    public function allowsMimeType(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), array_map('strtolower', $this->allowedMimeTypes()), true);
    }

    public function isVideo(): bool
    {
        return $this === self::Video;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
