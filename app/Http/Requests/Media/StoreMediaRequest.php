<?php

namespace App\Http\Requests\Media;

use App\Enums\MediaType;
use App\Http\Requests\Concerns\ValidatesAudience;
use App\Models\Character;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMediaRequest extends FormRequest
{
    use ValidatesAudience;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->isApproved() && $user->canLogin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(MediaType::values())],
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', 'max:255'],
            'size' => ['nullable', 'integer', 'min:1'],
            'title' => ['nullable', 'string', 'max:255'],
            'character_id' => [
                'nullable',
                'integer',
                Rule::exists('characters', 'id')->where('user_id', $this->user()?->id),
            ],
            ...$this->audienceRules(['required_without:character_id']),
            'interest_ids' => ['nullable', 'array'],
            'interest_ids.*' => ['integer', 'distinct', Rule::exists('interests', 'id')],
            // The client generates the thumbnail/poster itself; ask for a second
            // presigned URL when it has one to upload.
            'has_thumbnail' => ['nullable', 'boolean'],
            // Base64 of a 32-byte blockhash (44 chars incl. padding). Photos only.
            'perceptual_hash' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * Enforce per-type MIME and size constraints that depend on the chosen type.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $typeValue = $this->input('type');
            if (! in_array($typeValue, MediaType::values(), true)) {
                return;
            }

            $type = MediaType::from($typeValue);

            $contentType = (string) $this->input('content_type');
            if ($contentType !== '' && ! $type->allowsMimeType($contentType)) {
                $validator->errors()->add('content_type', 'This file type is not allowed for '.$typeValue.' uploads.');
            }

            $size = $this->input('size');
            if (is_numeric($size) && (int) $size > $type->maxBytes()) {
                $validator->errors()->add('size', 'This file exceeds the maximum allowed size.');
            }

            if ($this->input('character_id') === null) {
                $this->validateSpecificAudienceMembersAreMutuals($validator);
            }
        });
    }

    /**
     * @return list<int>
     */
    public function interestIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('interest_ids', [])));
    }

    public function character(): ?Character
    {
        $id = $this->input('character_id');
        if ($id === null) {
            return null;
        }

        return Character::query()
            ->with('audienceMembers')
            ->where('user_id', $this->user()?->id)
            ->find((int) $id);
    }
}
