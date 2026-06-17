<?php

namespace App\Http\Requests\Push;

use Illuminate\Foundation\Http\FormRequest;

class DeletePushSubscriptionRequest extends FormRequest
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
            'endpoint' => ['required', 'string', 'url', 'max:500'],
        ];
    }
}
