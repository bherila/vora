<?php

namespace App\Models;

use App\Support\WebPushKeyMaterial;
use Illuminate\Database\Eloquent\Casts\Attribute;
use NotificationChannels\WebPush\PushSubscription as BasePushSubscription;

class PushSubscription extends BasePushSubscription
{
    /**
     * @return Attribute<string|null, string|null>
     */
    protected function publicKey(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): ?string => WebPushKeyMaterial::binaryToBase64Url($attributes['public_key_bytes'] ?? null),
            set: fn (?string $value): array => ['public_key_bytes' => WebPushKeyMaterial::base64UrlToBinary($value)],
        );
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function authToken(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): ?string => WebPushKeyMaterial::binaryToBase64Url($attributes['auth_token_bytes'] ?? null),
            set: fn (?string $value): array => ['auth_token_bytes' => WebPushKeyMaterial::base64UrlToBinary($value)],
        );
    }
}
