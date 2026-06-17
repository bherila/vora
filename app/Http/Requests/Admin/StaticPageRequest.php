<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Unique slug so a clashing create/rename returns a 422 instead of the
            // database's unique-index integrity exception (a 500). On update, the
            // bound page is ignored so an unchanged slug still validates.
            'slug' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('static_pages', 'slug')->ignore($this->route('staticPage')),
            ],
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
