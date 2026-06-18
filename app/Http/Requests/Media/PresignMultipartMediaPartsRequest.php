<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class PresignMultipartMediaPartsRequest extends FormRequest
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
        $maxParts = (int) config('media.multipart.max_parts', 10000);

        return [
            'upload_id' => ['required', 'string', 'max:1024'],
            'part_numbers' => ['required', 'array', 'min:1', 'max:1000'],
            'part_numbers.*' => ['required', 'integer', 'min:1', 'max:'.$maxParts, 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function partNumbers(): array
    {
        return array_values(array_map('intval', $this->validated('part_numbers')));
    }
}
