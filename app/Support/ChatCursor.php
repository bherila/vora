<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;

final class ChatCursor
{
    public static function encode(string $kind, string $timestamp, string $ulid): string
    {
        return Crypt::encryptString(json_encode([
            'kind' => $kind,
            'timestamp' => $timestamp,
            'ulid' => $ulid,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{timestamp: string, ulid: string}
     */
    public static function decode(string $cursor, string $kind): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
        }

        if (! is_array($payload)
            || ($payload['kind'] ?? null) !== $kind
            || ! is_string($payload['timestamp'] ?? null)
            || ! is_string($payload['ulid'] ?? null)) {
            throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
        }

        try {
            $timestamp = CarbonImmutable::parse($payload['timestamp'])->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
        }

        return ['timestamp' => $timestamp, 'ulid' => $payload['ulid']];
    }
}
