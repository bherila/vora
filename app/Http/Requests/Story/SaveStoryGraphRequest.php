<?php

namespace App\Http\Requests\Story;

use Illuminate\Foundation\Http\FormRequest;

class SaveStoryGraphRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->isApproved() || ! $user->canLogin()) {
            return false;
        }

        // Authorize the target story before validation, so a non-author saving a
        // graph onto an existing story gets the same generic 404 as a missing story
        // rather than a 422 that would reveal the story exists.
        abort_unless($user->can('update', $this->route('story')), 404, 'Not found.');

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nodes' => ['present', 'array'],
            // Distinct keys so two passages can't reconcile into one row (which
            // could, e.g., clobber the start passage).
            'nodes.*.key' => ['nullable', 'string', 'max:64', 'distinct'],
            'nodes.*.title' => ['nullable', 'string', 'max:255'],
            'nodes.*.body' => ['nullable', 'string'],
            'nodes.*.is_start' => ['nullable', 'boolean'],
            'nodes.*.position_x' => ['nullable', 'numeric'],
            'nodes.*.position_y' => ['nullable', 'numeric'],
            'choices' => ['present', 'array'],
            'choices.*.from' => ['required', 'string', 'max:64'],
            'choices.*.to' => ['nullable', 'string', 'max:64'],
            'choices.*.label' => ['required', 'string', 'max:255'],
            'choices.*.position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
