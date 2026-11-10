<?php

namespace App\Http\Requests\Location;

use App\Services\Location\PlaceImportOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Queue a GeoNames import. "download" fetches the configured dataset;
 * "upload" takes the three GeoNames files; "preview" applies the dry run
 * named by preview_id, with its own options and files.
 */
class AdminPlacesImportRequest extends FormRequest
{
    /** Largest accepted upload, in kilobytes (cities500.zip is ~12 MB). */
    public const MAX_UPLOAD_KB = 102400;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $upload = $this->input('source') === 'upload';
        $preview = $this->input('source') === 'preview';

        return [
            'source' => ['required', Rule::in(['download', 'upload', 'preview'])],
            'mode' => [Rule::requiredIf(! $preview), 'nullable', Rule::in(PlaceImportOptions::MODES)],
            'preview_id' => [Rule::requiredIf($preview), 'nullable', 'uuid'],
            'rehome' => ['sometimes', 'boolean'],
            'dry_run' => ['sometimes', 'boolean'],
            'cities' => [Rule::requiredIf($upload), 'nullable', 'file', 'max:'.self::MAX_UPLOAD_KB, 'extensions:zip,txt'],
            'admin1' => [Rule::requiredIf($upload), 'nullable', 'file', 'max:10240', 'extensions:txt'],
            'countries' => [Rule::requiredIf($upload), 'nullable', 'file', 'max:2048', 'extensions:txt'],
        ];
    }
}
