<?php

namespace App\Http\Requests\Interest;

use Illuminate\Foundation\Http\FormRequest;

class RateInterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'level' => ['required', 'integer', 'between:-10,10'],
        ];
    }
}

