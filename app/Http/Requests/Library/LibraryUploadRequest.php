<?php

namespace App\Http\Requests\Library;

use App\Http\Requests\Media\StoreMediaRequest;
use Illuminate\Validation\Rule;

class LibraryUploadRequest extends StoreMediaRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()?->library_enabled === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['audience' => 'specific', 'audience_user_ids' => [], 'discoverable' => false, 'announce' => false]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'character_id' => ['prohibited'],
            'collection_ulid' => ['nullable', 'ulid', Rule::exists('library_collections', 'ulid')->where('user_id', $this->user()?->id)],
        ];
    }
}
