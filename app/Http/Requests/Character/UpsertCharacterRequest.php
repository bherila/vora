<?php

namespace App\Http\Requests\Character;

use App\Http\Requests\Concerns\ValidatesAudience;
use App\Models\Character;
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
            // Persona preferred_* columns are intentionally not accepted:
            // switching author identity never changes what the account sees.
            ...$this->audienceRules(['nullable']),
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $effectiveAudience = $this->audience();
            $character = $this->route('character');
            if (! $this->has('audience') && $character instanceof Character) {
                $effectiveAudience = $character->audience;
            }

            $this->validateSpecificAudienceMembersAreMutuals($validator, $effectiveAudience);
        });
    }
}
