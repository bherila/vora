<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUserIndexRequest extends FormRequest
{
    public const STATUSES = ['all', 'pending', 'restricted', 'banned', 'admins', 'deleted'];

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
