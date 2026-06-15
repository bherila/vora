<?php

namespace App\Http\Requests\Interest;

use App\Models\Character;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SetInterestInheritanceRequest extends FormRequest
{
    /**
     * Inheritance only applies to a character. Ownership is enforced here,
     * before validation, so a cross-user (or unknown) character is hidden as
     * 404 rather than leaking a 422 for an invalid payload.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $character = Character::query()->find($this->input('character_id'));

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
            'character_id' => ['required', 'integer'],
            'inherit' => ['required', 'boolean'],
        ];
    }
}
