<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
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
        $userId = $this->user()?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other'])],
            'gender_other' => ['required_if:gender,other', 'nullable', 'string', 'max:100'],
            'user_type' => ['nullable', 'string', Rule::in(['human', 'furry', 'other'])],
            'user_type_other' => ['required_if:user_type,other', 'nullable', 'string', 'max:100'],
            'preferred_user_types' => ['nullable', 'array'],
            'preferred_user_types.*' => ['required', 'string', 'distinct', Rule::in(['human', 'furry', 'other'])],
            'preferred_genders' => ['nullable', 'array'],
            'preferred_genders.*' => ['required', 'string', 'distinct', Rule::in(['male', 'female', 'other'])],
            'email_follow_request_received' => ['sometimes', 'boolean'],
            'email_follow_request_accepted' => ['sometimes', 'boolean'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
        ];
    }
}
