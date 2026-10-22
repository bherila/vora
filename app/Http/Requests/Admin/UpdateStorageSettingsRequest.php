<?php

namespace App\Http\Requests\Admin;

use App\Services\SettingsService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStorageSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'default_storage_limit_bytes' => ['present', 'nullable', 'integer', 'min:0', 'max:'.SettingsService::MAX_STORAGE_LIMIT_BYTES],
        ];
    }
}
