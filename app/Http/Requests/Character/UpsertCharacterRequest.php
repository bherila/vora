<?php

namespace App\Http\Requests\Character;

use App\Http\Requests\Concerns\ValidatesAudience;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertCharacterRequest extends FormRequest
{
    use ValidatesAudience;

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
            // Linked/Separate: whether the persona page names its owner. Omitted
            // means "leave as is" (create defaults to linked in the model).
            'is_linked' => ['sometimes', 'boolean'],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other'])],
            'gender_other' => ['required_if:gender,other', 'nullable', 'string', 'max:100'],
            'user_type' => ['nullable', 'string', Rule::in(['human', 'furry', 'other'])],
            'user_type_other' => ['required_if:user_type,other', 'nullable', 'string', 'max:100'],
            'preferred_user_types' => ['nullable', 'array'],
            'preferred_user_types.*' => ['required', 'string', 'distinct', Rule::in(['human', 'furry', 'other'])],
            'preferred_genders' => ['nullable', 'array'],
            'preferred_genders.*' => ['required', 'string', 'distinct', Rule::in(['male', 'female', 'other'])],
            ...$this->audienceRules(['nullable']),
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateSpecificAudienceMembersAreMutuals($validator));
    }
}
