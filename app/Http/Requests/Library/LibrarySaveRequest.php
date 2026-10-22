<?php

namespace App\Http\Requests\Library;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LibrarySaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->library_enabled === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'media_ulid' => ['required_without:item_ulid', 'prohibits:item_ulid', 'ulid'],
            'item_ulid' => ['required_without:media_ulid', 'prohibits:media_ulid', 'ulid'],
            'collection_ulid' => ['nullable', 'ulid', Rule::exists('library_collections', 'ulid')->where('user_id', $this->user()?->id)],
        ];
    }
}
