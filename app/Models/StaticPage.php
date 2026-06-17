<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaticPage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'title',
        'body_markdown',
        'variables',
        'is_published',
        'show_in_footer',
        'footer_label',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    public function variablesArray(): array
    {
        $decoded = json_decode($this->variables ?? '{}', true);

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'show_in_footer' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
