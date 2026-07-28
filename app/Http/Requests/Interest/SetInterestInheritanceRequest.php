<?php

namespace App\Http\Requests\Interest;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SetInterestInheritanceRequest extends FormRequest
{
    /**
     * Inheritance only applies to a character.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->isApproved() && $user->canLogin();
    }

    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException('Not found.');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()?->id ?? 0;

        return [
            'character_id' => [
                'required',
                'bail',
                'integer',
                Rule::exists('characters', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('user_id', $userId)
                        ->whereNull('deleted_at'),
                ),
            ],
            'inherit' => ['required', 'boolean'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        if (isset($validator->failed()['character_id']['Exists'])) {
            throw new NotFoundHttpException('Not found.');
        }

        parent::failedValidation($validator);
    }
}
