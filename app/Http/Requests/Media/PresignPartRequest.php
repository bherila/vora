<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class PresignPartRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is enforced in the controller via the media policy.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'upload_id' => ['required', 'string', 'max:1024'],
            // S3/R2 allow up to 10,000 parts per multipart upload.
            'part_number' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
