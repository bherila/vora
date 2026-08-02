<?php

namespace App\Http\Requests\Media;

use App\Enums\RestrictionCapability;
use App\Services\Moderation\RestrictionGate;
use Illuminate\Foundation\Http\FormRequest;

class PresignMultipartMediaPartsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ! app(RestrictionGate::class)->denies($user, RestrictionCapability::MediaUpload);
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
            'part_sizes' => ['required', 'array', 'min:1', 'max:1000'],
            'part_sizes.*' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return list<int>
     */
    public function partNumbers(): array
    {
        return array_values(array_map('intval', $this->validated('part_numbers')));
    }

    /**
     * @return array<int, int>
     */
    public function partSizes(): array
    {
        $sizes = [];
        foreach ($this->validated('part_sizes') as $partNumber => $sizeBytes) {
            $sizes[(int) $partNumber] = (int) $sizeBytes;
        }

        return $sizes;
    }
}
