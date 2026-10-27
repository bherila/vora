<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppIconRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Raster only (no SVG), and bounded so decoding stays within PHP memory.
        return [
            'icon' => ['required', 'file', 'max:5120', 'mimes:png,jpg,jpeg,webp', 'dimensions:min_width=192,min_height=192,max_width=4096,max_height=4096'],
        ];
    }
}
