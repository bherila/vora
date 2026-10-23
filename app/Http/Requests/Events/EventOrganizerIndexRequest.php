<?php

namespace App\Http\Requests\Events;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventOrganizerIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'pending', 'approved'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
