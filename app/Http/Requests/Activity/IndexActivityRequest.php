<?php

namespace App\Http\Requests\Activity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', Rule::in(['posts', 'comments', 'replies'])],
        ];
    }
}
