<?php

namespace App\Http\Requests\Library;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LibraryMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canUseLibrary() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['collection_ulid' => ['required', 'ulid', Rule::exists('library_collections', 'ulid')->where('user_id', $this->user()?->id)]];
    }
}
