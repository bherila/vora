<?php

namespace App\Http\Requests\Story;

use App\Enums\StoryStatus;
use App\Enums\StoryType;
use App\Http\Requests\Concerns\ValidatesAudience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStoryRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(StoryType::values())],
            'status' => ['nullable', Rule::in(StoryStatus::values())],
            ...$this->audienceRules(['nullable']),
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
