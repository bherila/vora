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
        if ($user === null || ! $user->isApproved() || ! $user->canLogin()) {
            return false;
        }

        // Authorize the target post before validation, so reacting to a post the
        // viewer cannot see returns the same generic 404 as a missing post instead
        // of a 422 (e.g. an invalid reaction type) that would reveal it exists.
        abort_unless($user->can('view', $this->route('post')), 404, 'Not found.');

        return true;
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
