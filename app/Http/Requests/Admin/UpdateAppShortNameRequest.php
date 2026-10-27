<?php

namespace App\Http\Requests\Admin;

use App\Services\Branding\AppIcon;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAppShortNameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Empty clears the setting, so APP_NAME is used again.
        return [
            'short_name' => ['present', 'nullable', 'string', 'max:'.AppIcon::SHORT_NAME_MAX],
        ];
    }
}
