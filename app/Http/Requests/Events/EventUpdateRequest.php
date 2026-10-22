<?php

namespace App\Http\Requests\Events;

use App\Enums\EventVisibility;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EventUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [...($this->isMethod('post') ? [] : ['sometimes']), 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'visibility' => ['sometimes', Rule::enum(EventVisibility::class)],
            'timezone' => ['sometimes', 'timezone:all'],
            'starts_at' => ['sometimes', 'nullable', 'date'], 'ends_at' => ['sometimes', 'nullable', 'date'],
            'member_ids' => ['sometimes', 'array', 'max:1000'],
            'member_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where(fn ($query) => $query
                ->whereNotNull('approved_at')->whereNull('deleted_at')->whereNull('deactivated_at')->where('is_disabled', false)
                ->where(fn ($active) => $active->whereNull('banned_at')->orWhere('ban_hides_content', false)))],
        ];
    }

    /** @return array<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['starts_at', 'ends_at'])) {
                return;
            }
            $event = $this->route('event');
            $start = $this->input('starts_at', $event instanceof Event ? $event->starts_at : null);
            $end = $this->input('ends_at', $event instanceof Event ? $event->ends_at : null);
            if ($start !== null && $end !== null && CarbonImmutable::parse($end)->lt(CarbonImmutable::parse($start))) {
                $validator->errors()->add('ends_at', 'The end must be after the start.');
            }
        }];
    }
}
