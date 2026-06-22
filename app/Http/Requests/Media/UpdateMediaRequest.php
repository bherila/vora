<?php

namespace App\Http\Requests\Media;

use App\Enums\Audience;
use App\Http\Requests\Concerns\ValidatesAudience;
use App\Models\Character;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a single-item media edit (title, privacy, character). Authorization
 * (ownership) is handled by the controller via the media policy; this only
 * checks the inputs. Privacy fields mirror the bulk request so both validate
 * identically.
 */
class UpdateMediaRequest extends FormRequest
{
    use ValidatesAudience;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->isApproved() && $user->canLogin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'character_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('characters', 'id')->where('user_id', $this->user()?->id),
            ],
            ...$this->audienceRules(['sometimes', 'required']),
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('audience') === Audience::SpecificPeople->value) {
                $this->validateSpecificAudienceMembersAreMutuals($validator);
            }
        });
    }

    public function character(): ?Character
    {
        $id = $this->input('character_id');
        if ($id === null) {
            return null;
        }

        return Character::query()
            ->with('audienceMembers')
            ->where('user_id', $this->user()?->id)
            ->find((int) $id);
    }
}
