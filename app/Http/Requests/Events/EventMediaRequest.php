<?php

namespace App\Http\Requests\Events;

use Illuminate\Foundation\Http\FormRequest;

class EventMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'event_ulid' => ['nullable', 'ulid'],
            'attendee_id' => ['nullable', 'integer', 'min:1'],
            'favorites_of' => ['nullable', 'integer', 'min:1'],
            'featured' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
