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
                    $error = WebPushEndpoint::validationError((string) $value);
                    if ($error !== null) {
                        $fail($error);
                    }
                },
            ],
            'keys' => ['required', 'array'],
            'keys.p256dh' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! WebPushKeyMaterial::isValidP256dh((string) $value)) {
                        $fail('The p256dh key is not valid Web Push key material.');
                    }
                },
            ],
            'keys.auth' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! WebPushKeyMaterial::isValidAuthToken((string) $value)) {
                        $fail('The auth key is not valid Web Push key material.');
                    }
                },
            ],
            'content_encoding' => ['nullable', 'string', Rule::in(['aesgcm', 'aes128gcm'])],
        ];
    }
}
