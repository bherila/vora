<?php

namespace App\Http\Requests\Character;

use App\Enums\MediaType;
use App\Enums\RestrictionCapability;
use App\Http\Requests\Profile\StoreProfilePictureRequest;
use App\Services\Moderation\RestrictionGate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreCharacterProfilePictureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ! app(RestrictionGate::class)->denies($user, RestrictionCapability::MediaUpload);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', 'max:255'],
            'size' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Character avatars are photos and must satisfy the same MIME allowlist and
     * size cap as user profile pictures ({@see StoreProfilePictureRequest}).
     * A bare `starts_with:image/` would admit active formats like image/svg+xml,
     * which are served inline from signed view URLs.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = MediaType::Photo;
            $contentType = $this->input('content_type');

            // after() runs even when the base `string` rule already failed, so a
            // non-string (e.g. an array) would raise "Array to string conversion".
            // Skip the allowlist check — the `string` rule already yields a 422.
            if (is_string($contentType) && $contentType !== '' && ! $type->allowsMimeType($contentType)) {
                $validator->errors()->add('content_type', 'Character profile pictures must be uploaded as an allowed image type.');
            }

            $size = $this->input('size');
            if (is_numeric($size) && (int) $size > $type->maxBytes()) {
                $validator->errors()->add('size', 'This file exceeds the maximum allowed size.');
            }
        });
    }
}
