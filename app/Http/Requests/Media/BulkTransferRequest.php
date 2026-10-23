<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class BulkTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && (! $this->routeIs('bulk-transfer.*.save') || $this->user()->canUseLibrary());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ulids' => ['required', 'array', 'list', 'min:1', 'max:'.($this->routeIs('bulk-transfer.*.save') ? 100 : 50)],
            'ulids.*' => ['required', 'ulid', 'distinct:strict'],
        ];
    }
}
