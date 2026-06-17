<?php

namespace App\Http\Requests\Profile;

use App\Enums\MediaType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreProfilePictureRequest extends FormRequest
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
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', 'max:255'],
            'size' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = MediaType::Photo;
            $contentType = $this->input('content_type');

            // after() runs even when the base `string` rule already failed, so a
            // non-string (e.g. an array) would raise "Array to string conversion".
            if (is_string($contentType) && $contentType !== '' && ! $type->allowsMimeType($contentType)) {
                $validator->errors()->add('content_type', 'Profile pictures must be uploaded as an image.');
            }

            $size = $this->input('size');
            if (is_numeric($size) && (int) $size > $type->maxBytes()) {
                $validator->errors()->add('size', 'This file exceeds the maximum allowed size.');
            }
        });
    }
}
