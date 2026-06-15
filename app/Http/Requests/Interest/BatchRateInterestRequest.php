<?php

namespace App\Http\Requests\Interest;

use App\Models\Character;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BatchRateInterestRequest extends FormRequest
{
    /**
     * Ratings are set for (user, character). character_id is optional — null
     * targets the user's own profile. Ownership is enforced here, before
     * validation, so a cross-user (or unknown) character is hidden as 404
     * rather than leaking a 422 for an invalid payload.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        if (! $user->isApproved() || ! $user->canLogin()) {
            return false;
        }

        $characterId = $this->input('character_id');
        if ($characterId === null) {
            return true;
        }

        // Guard against non-scalar input (e.g. an array), which Eloquent find()
        // would treat as a multi-key lookup and 500 on the ->user_id access.
        if (! is_numeric($characterId)) {
            return false;
        }

        $character = Character::query()->find((int) $characterId);

        return $character !== null && $character->user_id === $user->id;
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
        return [
            'character_id' => ['nullable', 'integer'],
            'ratings' => ['required', 'array', 'min:1'],
            'ratings.*.interest_id' => ['required', 'integer', 'exists:interests,id'],
            // A null level removes the rating; any other value upserts it.
            'ratings.*.level' => ['present', 'nullable', 'integer', 'between:-10,10'],
        ];
    }
}
