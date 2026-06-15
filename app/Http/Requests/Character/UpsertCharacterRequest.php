<?php

namespace App\Http\Requests\Character;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertCharacterRequest extends FormRequest
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
            'display_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other'])],
            'gender_other' => ['required_if:gender,other', 'nullable', 'string', 'max:100'],
            'user_type' => ['nullable', 'string', Rule::in(['human', 'furry', 'other'])],
            'user_type_other' => ['required_if:user_type,other', 'nullable', 'string', 'max:100'],
            'preferred_user_types' => ['nullable', 'array'],
            'preferred_user_types.*' => ['required', 'string', 'distinct', Rule::in(['human', 'furry', 'other'])],
            'preferred_genders' => ['nullable', 'array'],
            'preferred_genders.*' => ['required', 'string', 'distinct', Rule::in(['male', 'female', 'other'])],
        ];
    }
}
