<?php

namespace App\Http\Requests\Push;

use Illuminate\Contracts\Validation\Validator;
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
            'endpoint' => ['required', 'string', 'url', 'max:500'],
            'keys' => ['required', 'array'],
            // Web Push p256dh is an uncompressed EC key: 65 raw bytes → 87-88 base64url chars.
            'keys.p256dh' => ['required', 'string', 'min:80', 'max:120', 'regex:/^[A-Za-z0-9\-_]+=*$/'],
            // Web Push auth is 16 random bytes → 22-24 base64url chars.
            'keys.auth' => ['required', 'string', 'min:16', 'max:32', 'regex:/^[A-Za-z0-9\-_]+=*$/'],
            'content_encoding' => ['nullable', 'string', Rule::in(['aesgcm', 'aes128gcm'])],
        ];
    }

    /**
     * Block SSRF: the endpoint must be HTTPS and must not resolve to a private
     * or loopback address. Legitimate browser push services are always public
     * HTTPS endpoints; internal URLs could be used to probe internal services.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $endpoint = (string) $this->input('endpoint');
            $parsed = parse_url($endpoint);

            if (! isset($parsed['scheme']) || $parsed['scheme'] !== 'https') {
                $validator->errors()->add('endpoint', 'Push subscription endpoints must use HTTPS.');

                return;
            }

            $host = $parsed['host'] ?? '';
            // Block loopback, link-local, private ranges, and internal hostnames.
            if ($host === ''
                || $host === 'localhost'
                || str_ends_with($host, '.local')
                || str_ends_with($host, '.internal')
                || str_ends_with($host, '.localhost')) {
                $validator->errors()->add('endpoint', 'Push subscription endpoint host is not allowed.');

                return;
            }

            // Block private/loopback IP literals.
            $ip = filter_var($host, FILTER_VALIDATE_IP);
            if ($ip !== false && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $validator->errors()->add('endpoint', 'Push subscription endpoint host is not allowed.');
            }
        });
    }
}
