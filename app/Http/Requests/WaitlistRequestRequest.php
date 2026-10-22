<?php

namespace App\Http\Requests;

use App\Services\Auth\AgeEligibility;
use Illuminate\Foundation\Http\FormRequest;

class WaitlistRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'birth_date' => app(AgeEligibility::class)->birthDateRules(),
            'interests' => ['required', 'string', 'min:20', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'interests.min' => 'Please write a few sentences about your interests and why you want to join.',
        ];
    }
}
