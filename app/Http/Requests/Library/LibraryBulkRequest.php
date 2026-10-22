<?php

namespace App\Http\Requests\Library;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LibraryBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && (! in_array($this->input('action'), ['add', 'move'], true)
            || $this->user()->canUseLibrary());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['add', 'move', 'remove', 'delete'])],
            'item_ulids' => ['required', 'array', 'min:1', 'max:100'],
            'item_ulids.*' => ['required', 'ulid', 'distinct:strict'],
            'collection_ulid' => ['required_if:action,add,move', 'prohibited_unless:action,add,move', 'ulid', 'different:source_collection_ulid'],
            'source_collection_ulid' => ['required_if:action,move,remove', 'prohibited_unless:action,move,remove', 'ulid'],
        ];
    }
}
