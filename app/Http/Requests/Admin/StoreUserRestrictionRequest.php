<?php

namespace App\Http\Requests\Admin;

use App\Enums\RestrictionCapability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRestrictionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'capability' => ['required', Rule::enum(RestrictionCapability::class)],
            'reason' => ['nullable', 'string', 'max:5000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
