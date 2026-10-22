<?php

namespace App\Http\Requests\Library;

use App\Models\Character;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class LibraryCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canUseLibrary() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'related_persona_ulid' => ['nullable', 'ulid'],
            'related_character_id' => ['prohibited'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('related_persona_ulid') === null || $validator->errors()->has('related_persona_ulid')) {
                return;
            }

            $character = $this->relatedCharacter();
            if (! $character instanceof Character || ! $character->isViewableBy($this->user())) {
                $validator->errors()->add('related_persona_ulid', 'Choose an available persona.');
            }
        });
    }

    public function relatedCharacter(): ?Character
    {
        $ulid = $this->input('related_persona_ulid');

        return ! is_string($ulid) ? null : Character::query()->with('user')->where('ulid', $ulid)->first();
    }
}
