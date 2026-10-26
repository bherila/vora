<?php

namespace App\Http\Requests\Admin;

use App\Models\MailDelivery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminMailDeliveryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(MailDelivery::STATUS_RANK))],
            'search' => ['sometimes', 'nullable', 'string', 'max:254'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
