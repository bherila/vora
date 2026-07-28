<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateActiveIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isApproved() && $user->canLogin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()?->id ?? 0;

        return [
            'character_id' => [
                'present',
                'nullable',
                'bail',
                'integer',
                Rule::exists('characters', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('user_id', $userId)
                        ->whereNull('deleted_at'),
                ),
            ],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException('Not found.');
    }

    protected function failedValidation(Validator $validator): void
    {
        if (isset($validator->failed()['character_id']['Exists'])) {
            throw new NotFoundHttpException('Not found.');
        }

        parent::failedValidation($validator);
    }
}
