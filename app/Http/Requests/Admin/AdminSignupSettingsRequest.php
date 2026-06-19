<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminSignupSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'public_signups_enabled' => ['sometimes', 'boolean'],
            'default_new_user_invites' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'default_new_user_invite_expiry_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }
}
