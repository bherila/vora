<?php

namespace App\Http\Requests\Story;

use App\Enums\StoryStatus;
use App\Http\Requests\Concerns\ValidatesAudience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoryRequest extends FormRequest
{
    use ValidatesAudience;

    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->isApproved() || ! $user->canLogin()) {
            return false;
        }

        // Authorize the target story before validation, so a non-author editing an
        // existing story gets the same generic 404 as a missing story (the {story}
        // route binding already 404s missing rows) rather than a 422 that would
        // reveal the story exists.
        abort_unless($user->can('update', $this->route('story')), 404, 'Not found.');

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::in(StoryStatus::values())],
            ...$this->audienceRules(['sometimes', 'required']),
            'body' => ['nullable', 'string'],
            'interest_ids' => ['nullable', 'array'],
            'interest_ids.*' => ['integer', 'distinct', Rule::exists('interests', 'id')],
            'involvements' => ['nullable', 'array'],
            'involvements.*.type' => ['required', Rule::in(['user', 'character'])],
            'involvements.*.id' => ['required', 'integer'],
        ];
    }

    /**
     * @return list<int>
     */
    public function interestIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('interest_ids', [])));
    }
}
