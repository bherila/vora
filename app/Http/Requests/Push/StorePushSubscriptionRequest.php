<?php

namespace App\Http\Requests\Push;

use App\Support\WebPushEndpoint;
use App\Support\WebPushKeyMaterial;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint' => [
                'required',
                'string',
                'url',
                'max:500',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }
                    $error = WebPushEndpoint::validationError($value);
                    if ($error !== null) {
                        $fail($error);
                    }
                },
            ],
            'keys' => ['required', 'array'],
            // p256dh is a 65-byte EC point (~88 base64url chars) and auth is 16
            // bytes (~24 chars). Cap length and `bail` so an oversized payload is
            // rejected before the closure base64-decodes the whole string.
            'keys.p256dh' => [
                'bail',
                'required',
                'string',
                'max:128',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }
                    if (! WebPushKeyMaterial::isValidP256dh($value)) {
                        $fail('The p256dh key is not valid Web Push key material.');
                    }
                },
            ],
            'keys.auth' => [
                'bail',
                'required',
                'string',
                'max:64',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }
                    if (! WebPushKeyMaterial::isValidAuthToken($value)) {
                        $fail('The auth key is not valid Web Push key material.');
                    }
                },
            ],
            'content_encoding' => ['nullable', 'string', Rule::in(['aesgcm', 'aes128gcm'])],
        ];
    }
}
