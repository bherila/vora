<?php

namespace App\Http\Requests\Events;

use App\Enums\EventVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventAttendeeAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'event_ulids' => ['present', 'array', 'max:100'],
            'event_ulids.*' => ['required', 'string', 'ulid', 'distinct', Rule::exists('events', 'ulid')
                ->where('visibility', EventVisibility::Specific->value)],
        ];
    }
}
