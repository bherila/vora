<?php

namespace App\Support;

/** Still HEVC photos use a JPEG display derivative; originals remain untouched. */
class HeifPhoto
{
    public const PREVIEW_MAX_EDGE = 2048;

    public static function isMimeType(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), ['image/heic', 'image/heif'], true);
    }

    /** Check the bounded ISO BMFF file-type box, without decoding untrusted images. */
    public static function hasStillImageBrand(string $header): bool
    {
        if (strlen($header) < 16 || substr($header, 4, 4) !== 'ftyp') {
            return false;
        }
        $size = unpack('Nsize', substr($header, 0, 4))['size'];
        if ($size < 16 || $size > strlen($header) || $size > 4096 || $size % 4 !== 0) {
            return false;
        }
        $brands = [substr($header, 8, 4), ...str_split(substr($header, 16, $size - 16), 4)];
        if (array_intersect($brands, ['msf1', 'hevc', 'hevx']) !== []) {
            return false;
        }

        return array_intersect($brands, ['heic', 'heix']) !== [];
    }

    public static function isJpegPreview(string $bytes): bool
    {
        $dimensions = @getimagesizefromstring($bytes);

        return $dimensions !== false && $dimensions[2] === IMAGETYPE_JPEG
            && $dimensions[0] > 0 && $dimensions[1] > 0
            && max($dimensions[0], $dimensions[1]) <= self::PREVIEW_MAX_EDGE;
    }
}
