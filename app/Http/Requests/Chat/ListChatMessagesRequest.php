<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class ListChatMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isApproved() === true && $this->user()?->isActive() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string', 'max:2048', 'prohibits:after'],
            'after' => ['nullable', 'ulid', 'prohibits:cursor'],
        ];
    }
}
