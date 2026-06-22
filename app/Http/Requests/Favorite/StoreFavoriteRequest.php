<?php

namespace App\Http\Requests\Favorite;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a favorite toggle. Whether the viewer may actually favorite the
 * target (i.e. can see it at all) is enforced in the controller against the
 * item's own privacy policy — this only checks the inputs are well formed.
 */
class StoreFavoriteRequest extends FormRequest
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
            'type' => ['required', Rule::in(['media', 'story', 'post', 'user', 'character'])],
            'id' => ['required', 'integer', 'min:1'],
        ];
    }
}
