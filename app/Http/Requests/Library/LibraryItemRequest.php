<?php

namespace App\Http\Requests\Library;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LibraryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->library_enabled === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'body' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:100000'],
            'collection_ulid' => ['nullable', 'ulid', Rule::exists('library_collections', 'ulid')->where('user_id', $this->user()?->id)],
        ];
    }
}
