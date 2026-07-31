<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class SendChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isApproved() === true && $this->user()?->isActive() === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim($this->input('body'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_message_id' => [
                'required',
                'string',
                'max:36',
                'regex:/^(?:[0-9A-HJKMNP-TV-Z]{26}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/i',
            ],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
