<?php

namespace App\Http\Requests\Media;

use App\Enums\MediaPurpose;
use App\Models\Media;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class BulkMediaRequest extends FormRequest
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
            'media_ids' => ['required', 'array', 'min:1', 'max:100'],
            'media_ids.*' => ['integer', 'distinct'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ids = $this->mediaIds();
            if ($ids === []) {
                return;
            }

            $ownedCount = Media::query()
                ->whereIn('id', $ids)
                ->where('user_id', $this->user()?->id)
                ->where('purpose', MediaPurpose::Gallery->value)
                ->count();

            if ($ownedCount !== count($ids)) {
                $validator->errors()->add('media_ids', 'One or more selected media items could not be found.');
            }
        });
    }

    /**
     * @return list<int>
     */
    public function mediaIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->input('media_ids', []))));
    }
}
