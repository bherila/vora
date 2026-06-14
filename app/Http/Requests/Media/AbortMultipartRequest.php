<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class AbortMultipartRequest extends FormRequest
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
        ];
    }
}
