<?php

namespace App\Support;

/**
 * Helpers for comparing the client-generated perceptual (blockhash) hashes,
 * stored base64-encoded over 32 bytes (256 bits). Two images are "near
 * duplicates" when their hashes differ in only a few bits.
 */
class PerceptualHash
{
    /**
     * Hamming distance (number of differing bits) between two base64-encoded
     * hashes of equal byte length. Null when either input is missing or the two
     * cannot be compared (decode failure or length mismatch).
     */
    public static function hammingDistance(?string $a, ?string $b): ?int
    {
        if ($a === null || $b === null) {
            return null;
        }

        $bytesA = base64_decode($a, true);
        $bytesB = base64_decode($b, true);

        if ($bytesA === false || $bytesB === false || $bytesA === '' || strlen($bytesA) !== strlen($bytesB)) {
            return null;
        }

        $distance = 0;
        $length = strlen($bytesA);
        for ($i = 0; $i < $length; $i++) {
            $distance += self::popcount(ord($bytesA[$i]) ^ ord($bytesB[$i]));
        }

        return $distance;
    }

    /**
     * Hamming distance between two hex-encoded hashes of equal length (e.g. the
     * 64-char / 256-bit PDQ hashes produced by the image worker). Null when
     * either input is missing or the two cannot be compared (non-hex or length
     * mismatch).
     */
    public static function hammingDistanceHex(?string $a, ?string $b): ?int
    {
        if ($a === null || $b === null) {
            return null;
        }

        $bytesA = self::hexToBytes($a);
        $bytesB = self::hexToBytes($b);

        if ($bytesA === null || $bytesB === null || $bytesA === '' || strlen($bytesA) !== strlen($bytesB)) {
            return null;
        }

        $distance = 0;
        $length = strlen($bytesA);
        for ($i = 0; $i < $length; $i++) {
            $distance += self::popcount(ord($bytesA[$i]) ^ ord($bytesB[$i]));
        }

        return $distance;
    }

    /**
     * Decode an even-length hex string to raw bytes, or null when it is not
     * valid hex.
     */
    private static function hexToBytes(string $hex): ?string
    {
        if ($hex === '' || strlen($hex) % 2 !== 0 || preg_match('/[^0-9a-fA-F]/', $hex) === 1) {
            return null;
        }

        $bytes = hex2bin($hex);

        return $bytes === false ? null : $bytes;
    }

    private static function popcount(int $byte): int
    {
        $count = 0;
        while ($byte !== 0) {
            $count += $byte & 1;
            $byte >>= 1;
        }

        return $count;
    }
}
