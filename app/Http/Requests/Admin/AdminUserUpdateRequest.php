<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminUserUpdateRequest extends FormRequest
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
            'is_admin' => ['sometimes', 'boolean'],
            'is_disabled' => ['sometimes', 'boolean'],
            'id_verified' => ['sometimes', 'boolean'],
            'name_locked' => ['sometimes', 'boolean'],
            'email_locked' => ['sometimes', 'boolean'],
            'can_receive_invites' => ['sometimes', 'boolean'],
            'trusted_inviter' => ['sometimes', 'boolean'],
            'birth_date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:'.today()->subYears(18)->toDateString()],
        ];
    }
}
