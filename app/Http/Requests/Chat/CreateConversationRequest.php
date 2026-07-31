<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class CreateConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isApproved() === true && $this->user()?->isActive() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['recipient_id' => ['required', 'ulid']];
    }
}
