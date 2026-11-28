<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Names the moment whose frame becomes the video's poster. Ownership is
 * checked by the controller.
 */
class UseMomentAsCoverRequest extends FormRequest
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
            'moment' => ['required', 'string', 'size:26'],
        ];
    }
}
