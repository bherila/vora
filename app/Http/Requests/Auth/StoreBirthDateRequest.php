<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Auth\AgeEligibility;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A birth date recorded once by an account that has none: accounts created
 * through provider sign-in never passed the registration form that asks for
 * it. Corrections after that stay with administrators.
 */
class StoreBirthDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->birth_date === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['birth_date' => app(AgeEligibility::class)->birthDateRules()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $minimum = app(AgeEligibility::class)->policy()['minimum_age'];

        return $minimum === null ? [] : [
            'birth_date.before_or_equal' => "You must be at least {$minimum} years old to hold an account on this site.",
        ];
    }
}
