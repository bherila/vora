<?php

namespace App\Http\Requests\Admin;

use App\Models\Interest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminInterestStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('interests', 'name')],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_interest_id' => ['nullable', 'integer', Rule::exists('interests', 'id')],
        ];
    }
}

