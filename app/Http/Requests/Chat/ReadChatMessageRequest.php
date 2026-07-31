<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class ReadChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isApproved() === true && $this->user()?->isActive() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['message_id' => ['required', 'ulid']];
    }
}
