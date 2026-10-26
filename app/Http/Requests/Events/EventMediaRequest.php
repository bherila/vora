<?php

namespace App\Http\Requests\Events;

use App\Support\InstanceFeatures;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_if(! InstanceFeatures::enabled('favorites')
            && ($this->filled('favorites_of') || $this->boolean('featured')), 404);
        abort_if($this->boolean('review') && ! $this->user()?->isAdmin(), 403);

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
            'sort' => ['sometimes', 'string', Rule::in(['taken', 'recent'])],
            'review' => ['sometimes', 'boolean'],
        ];
    }
}
