<?php

namespace App\Http\Requests\Admin;

use App\Models\Interest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminInterestUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->isAdmin() && $user->isApproved() && $user->canLogin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Interest|null $interest */
        $interest = $this->route('interest');
        $interestId = is_int($interest?->id) ? (int) $interest->id : 0;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('interests', 'name')->ignore($interestId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_interest_id' => ['nullable', 'integer', Rule::exists('interests', 'id'), Rule::notIn([$interestId])],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Interest|null $interest */
            $interest = $this->route('interest');
            if ($interest === null) {
                return;
            }

            if (! $this->filled('parent_interest_id')) {
                return;
            }

            $parentId = $this->integer('parent_interest_id');
            if ($parentId === null) {
                return;
            }

            if ($this->wouldCreateCycle($interest, $parentId)) {
                $validator->errors()->add('parent_interest_id', 'The parent interest cannot be a child of this interest.');
            }
        });
    }

    private function wouldCreateCycle(Interest $interest, int $candidateParentId): bool
    {
        $currentId = $candidateParentId;
        while (true) {
            if ($currentId === $interest->id) {
                return true;
            }

            $currentId = Interest::query()
                ->where('id', $currentId)
                ->value('parent_interest_id');

            if ($currentId === null) {
                return false;
            }
        }
    }
}
