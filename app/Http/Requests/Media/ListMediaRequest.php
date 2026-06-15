<?php

namespace App\Http\Requests\Media;

use App\Enums\MediaType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the discovery filters accepted by every media listing endpoint
 * (the owner's library and cross-user exploration). Authorization of *which*
 * rows are visible is enforced per-endpoint, not here — this only checks that
 * the filter inputs are well formed.
 */
class ListMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->isApproved() && $user->canLogin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(MediaType::values())],
            'interest_ids' => ['nullable', 'array'],
            'interest_ids.*' => ['integer', 'distinct', Rule::exists('interests', 'id')],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
