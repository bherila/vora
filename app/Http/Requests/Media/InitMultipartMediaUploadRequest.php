<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class InitMultipartMediaUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
