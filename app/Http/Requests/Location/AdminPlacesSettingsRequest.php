<?php

namespace App\Http\Requests\Location;

use App\Services\Location\PlaceImportOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminPlacesSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dataset' => ['required', Rule::in(PlaceImportOptions::DATASETS)],
            'auto_refresh' => ['required', 'boolean'],
        ];
    }
}
