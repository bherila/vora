<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminInterestStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->isAdmin() && $user->isApproved() && $user->canLogin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('interests', 'name')],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash:ascii', Rule::unique('interests', 'slug')],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_interest_id' => ['nullable', 'integer', Rule::exists('interests', 'id')],
        ];
    }
}
