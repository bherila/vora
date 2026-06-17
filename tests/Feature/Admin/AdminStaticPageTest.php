<?php

namespace Tests\Feature\Admin;

use App\Models\StaticPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStaticPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'about-us',
            'title' => 'About Us',
            'body_markdown' => 'Hello.',
            'is_published' => true,
            'show_in_footer' => false,
            'sort_order' => 0,
        ], $overrides);
    }

    public function test_creating_a_page_with_an_existing_slug_is_rejected_with_422(): void
    {
        $admin = User::factory()->admin()->create();
        StaticPage::query()->create($this->payload([
            'variables' => json_encode([], JSON_THROW_ON_ERROR),
        ]));

        // A clashing slug should validate as 422, not surface the database's
        // unique-index integrity exception as a 500.
        $this->actingAs($admin)->postJson('/api/admin/pages', $this->payload(['title' => 'Another']))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('slug');
    }

    public function test_updating_a_page_keeps_its_own_slug(): void
    {
        $admin = User::factory()->admin()->create();
        $page = StaticPage::query()->create($this->payload([
            'variables' => json_encode([], JSON_THROW_ON_ERROR),
        ]));

        // Re-saving with the same slug must not trip the unique rule on itself.
        $this->actingAs($admin)->putJson("/api/admin/pages/{$page->id}", $this->payload(['title' => 'Updated']))
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated');
    }
}
