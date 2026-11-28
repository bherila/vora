<?php

namespace App\Http\Requests\Media;

use App\Models\VideoMoment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a caption or visibility change. Authorship is checked by the
 * controller.
 */
class UpdateVideoMomentRequest extends FormRequest
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
            'caption' => ['sometimes', 'nullable', 'string', 'max:'.VideoMoment::MAX_CAPTION_LENGTH, 'not_regex:/[\x00-\x09\x0B-\x1F\x7F]/u'],
            'visibility' => ['sometimes', Rule::in([VideoMoment::VISIBILITY_SHARED, VideoMoment::VISIBILITY_PRIVATE])],
        ];
    }
}
