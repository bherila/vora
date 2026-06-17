<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StaticPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin-only') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'title' => ['required', 'string', 'max:255'],
            'body_markdown' => ['required', 'string'],
            'variables' => ['nullable', 'array'],
            'variables.*' => ['nullable', 'string', 'max:2000'],
            'is_published' => ['required', 'boolean'],
            'show_in_footer' => ['required', 'boolean'],
            'footer_label' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
