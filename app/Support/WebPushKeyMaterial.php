<?php

namespace App\Support;

class WebPushKeyMaterial
{
    public static function base64UrlToBinary(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value)) {
            return null;
        }

        $unpadded = rtrim($value, '=');
        if (str_contains($unpadded, '=') || strlen($unpadded) % 4 === 1) {
            return null;
        }

        $base64 = strtr($unpadded, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $decoded = base64_decode($base64, true);

        return is_string($decoded) ? $decoded : null;
    }

    public static function binaryToBase64Url(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function isValidP256dh(string $value): bool
    {
        $bytes = self::base64UrlToBinary($value);

        return is_string($bytes) && strlen($bytes) === 65 && $bytes[0] === "\x04";
    }

    public static function isValidAuthToken(string $value): bool
    {
        $bytes = self::base64UrlToBinary($value);

        return is_string($bytes) && strlen($bytes) === 16;
    }
}
