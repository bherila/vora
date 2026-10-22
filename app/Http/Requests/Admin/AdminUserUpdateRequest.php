<?php

namespace App\Http\Requests\Admin;

use App\Services\Auth\AgeEligibility;
use App\Services\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'library_enabled' => ['sometimes', 'boolean'],
            'storage_limit_bytes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.SettingsService::MAX_STORAGE_LIMIT_BYTES],
            'storage_unlimited' => ['sometimes', 'boolean'],
            'birth_date' => app(AgeEligibility::class)->birthDateRules(adminCorrection: true),
        ];
    }

    /** @return array<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->boolean('storage_unlimited') && $this->input('storage_limit_bytes') !== null) {
                $validator->errors()->add('storage_limit_bytes', 'Choose either a finite limit or unlimited storage.');
            }
        }];
    }
}
