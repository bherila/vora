<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query for scene suggestions. Ownership is checked by the controller.
 */
class SceneSuggestionsRequest extends FormRequest
{
    public const DEFAULT_MIN_GAP_SECONDS = 20;

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
            'min_gap_seconds' => ['sometimes', 'integer', 'min:1', 'max:600'],
        ];
    }

    public function minGapMs(): int
    {
        return (int) $this->validated('min_gap_seconds', self::DEFAULT_MIN_GAP_SECONDS) * 1000;
    }
}
