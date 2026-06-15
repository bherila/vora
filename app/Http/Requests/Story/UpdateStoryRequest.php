<?php

namespace App\Http\Requests\Story;

use App\Enums\StoryStatus;
use App\Enums\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoryRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::in(StoryStatus::values())],
            'visibility' => ['sometimes', 'required', Rule::in(Visibility::values())],
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
