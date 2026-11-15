<?php

namespace App\Http\Requests\Interest;

use App\Http\Requests\Concerns\CapsArrayInput;
use App\Rules\AllExist;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BatchRateInterestRequest extends FormRequest
{
    use CapsArrayInput;

    /**
     * Ratings are set for (user, character). character_id is optional — null
     * targets the user's own profile.
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
                'nullable',
                'bail',
                'integer',
                Rule::exists('characters', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('user_id', $userId)
                        ->whereNull('deleted_at'),
                ),
            ],
            // The editor restores at most one character's whole rating set at once.
            'ratings' => ['required', 'array', 'min:1', 'max:500', new AllExist('interests', key: 'interest_id')],
            'ratings.*.interest_id' => ['required', 'integer'],
            // A null level removes the rating; any other value upserts it.
            'ratings.*.level' => ['present', 'nullable', 'integer', 'between:-10,10'],
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
