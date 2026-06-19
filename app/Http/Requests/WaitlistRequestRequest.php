<?php

namespace App\Http\Requests;

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
            // Mirror the registration age gate: must be at least 18 today.
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.today()->subYears(18)->toDateString()],
            'interests' => ['required', 'string', 'min:20', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'birth_date.before_or_equal' => 'You must be at least 18 years old to request an invitation.',
            'interests.min' => 'Please write a few sentences about your interests and why you want to join.',
        ];
    }
}
