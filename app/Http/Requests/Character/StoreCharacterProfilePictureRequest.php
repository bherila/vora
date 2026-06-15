<?php

namespace App\Http\Requests\Character;

use Illuminate\Foundation\Http\FormRequest;

class StoreCharacterProfilePictureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', 'starts_with:image/'],
            'size' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
