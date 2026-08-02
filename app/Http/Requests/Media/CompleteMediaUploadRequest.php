<?php

namespace App\Http\Requests\Media;

use App\Enums\RestrictionCapability;
use App\Services\Moderation\RestrictionGate;
use Illuminate\Foundation\Http\FormRequest;

class CompleteMediaUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ! app(RestrictionGate::class)->denies($user, RestrictionCapability::MediaUpload);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
