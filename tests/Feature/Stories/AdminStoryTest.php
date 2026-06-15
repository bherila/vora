<?php

namespace Tests\Feature\Stories;

use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_and_moderate_stories(): void
    {
        $admin = User::factory()->approved()->create(['is_admin' => true]);
        $owner = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->published()->create();

        $this->actingAs($admin)->getJson('/api/admin/stories')
            ->assertOk()
            ->assertJsonPath('data.0.moderation_status', 'pending');

        $this->actingAs($admin)->postJson("/api/admin/stories/{$story->id}/moderate", ['action' => 'approve', 'notes' => 'ok'])
            ->assertOk()
            ->assertJsonPath('data.moderation_status', 'approved');

        $this->assertSame('approved', $story->refresh()->moderation_status->value);
        $this->assertSame($admin->id, $story->moderated_by_user_id);
    }

    public function test_non_admin_cannot_access_admin_story_endpoints(): void
    {
        // The first-created user is id 1, which the admin gate treats as admin;
        // create a filler so the user under test is a plain, non-admin account.
        User::factory()->create();
        $user = User::factory()->approved()->create();
        $story = Story::factory()->for($user)->create();

        $this->actingAs($user)->getJson('/api/admin/stories')->assertForbidden();
        $this->actingAs($user)->postJson("/api/admin/stories/{$story->id}/moderate", ['action' => 'approve'])->assertForbidden();
    }

    public function test_moderation_status_is_not_exposed_to_author(): void
    {
        $owner = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create();

        $this->actingAs($owner)->getJson("/api/stories/{$story->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.moderation_status');
    }
}
