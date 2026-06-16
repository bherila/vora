<?php

namespace App\Http\Requests\Post;

use App\Http\Requests\Concerns\ValidatesAudience;
use App\Services\Post\PostService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:2000'],
            'character_id' => ['nullable', 'integer'],
            ...$this->audienceRules(['nullable']),
            'attachments' => ['nullable', 'array', 'max:4'],
            'attachments.*.type' => ['required', Rule::in(array_keys(PostService::ATTACHMENT_TYPES))],
            'attachments.*.id' => ['required', 'integer'],
        ];
    }

    /**
     * The character to post as, if any.
     */
    public function characterId(): ?int
    {
        $value = $this->input('character_id');

        return $value === null ? null : (int) $value;
    }

    /**
     * @return list<array{type: string, id: int}>
     */
    public function attachmentsInput(): array
    {
        return collect($this->input('attachments', []))
            ->map(fn (array $attachment): array => ['type' => (string) $attachment['type'], 'id' => (int) $attachment['id']])
            ->all();
    }
}
