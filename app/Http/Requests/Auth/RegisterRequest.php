<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.today()->subYears(18)->toDateString()],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'gender' => ['required', 'string', Rule::in(['m', 'f', 'other'])],
            'gender_other' => ['required_if:gender,other', 'nullable', 'string', 'max:100'],
        ];
    }
}
