<?php

namespace App\Http\Requests\Media;

use App\Models\VideoMoment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new moment. Viewing access is checked by the controller; the
 * time is bounded by the video's duration once known (VideoMomentService).
 */
class StoreVideoMomentRequest extends FormRequest
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
            'time_ms' => ['required', 'integer', 'min:0', 'max:'.UpdateVideoChaptersRequest::MAX_START_MS],
            'caption' => ['nullable', 'string', 'max:'.VideoMoment::MAX_CAPTION_LENGTH, 'not_regex:/[\x00-\x09\x0B-\x1F\x7F]/u'],
            'visibility' => ['required', Rule::in([VideoMoment::VISIBILITY_SHARED, VideoMoment::VISIBILITY_PRIVATE])],
            'wants_provisional' => ['sometimes', 'boolean'],
            // Presigned uploads sign the exact length, so the capture's size
            // is declared up front.
            'capture_bytes' => ['required_if_accepted:wants_provisional', 'integer', 'min:1', 'max:'.(int) config('media.moments.provisional_max_bytes')],
        ];
    }

    public function caption(): ?string
    {
        $caption = $this->validated('caption');

        return is_string($caption) && trim($caption) !== '' ? trim($caption) : null;
    }
}
