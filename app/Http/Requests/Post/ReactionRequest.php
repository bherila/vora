<?php

namespace App\Http\Requests\Post;

use App\Models\PostReaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReactionRequest extends FormRequest
{
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
            'type' => ['nullable', Rule::in(PostReaction::TYPES)],
        ];
    }

    /**
     * The chosen reaction type, defaulting to the single "like" today.
     */
    public function reactionType(): string
    {
        $type = $this->input('type');

        return is_string($type) && in_array($type, PostReaction::TYPES, true)
            ? $type
            : PostReaction::DEFAULT_TYPE;
    }
}
