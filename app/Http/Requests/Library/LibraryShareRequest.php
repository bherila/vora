<?php

namespace App\Http\Requests\Library;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LibraryShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_ids' => ['present', 'array', 'max:100'],
            'user_ids.*' => ['integer', 'distinct', Rule::notIn([$this->user()?->id]),
                Rule::exists('users', 'id')->whereNotNull('approved_at')->whereNull('deleted_at')
                    ->whereNull('deactivated_at')->whereNull('banned_at')->where('is_disabled', false)],
        ];
    }
}
