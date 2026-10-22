<?php

namespace App\Http\Requests\Events;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'featured_curator_ids' => ['present', 'array', 'max:100'],
            'featured_curator_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where(fn ($query) => $query
                ->whereNotNull('approved_at')->whereNull('deleted_at')->whereNull('deactivated_at')->where('is_disabled', false)
                ->where(fn ($active) => $active->whereNull('banned_at')->orWhere('ban_hides_content', false)))],
        ];
    }
}
