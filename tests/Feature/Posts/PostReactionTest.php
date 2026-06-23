<?php

namespace Tests\Feature\Posts;

use App\Enums\Audience;
use App\Models\Post;
use App\Models\PostReaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostReactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_reacting_is_idempotent(): void
    {
        // Author first so the viewer is not user id 1 (always admin).
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();

        $this->actingAs($viewer)->postJson("/api/posts/{$post->id}/reactions")
            ->assertOk()
            ->assertJsonPath('data.reaction_count', 1)
            ->assertJsonPath('data.viewer_reacted', true);

        // Reacting again does not create a duplicate.
        $this->actingAs($viewer)->postJson("/api/posts/{$post->id}/reactions")
            ->assertOk()
            ->assertJsonPath('data.reaction_count', 1);

        $this->assertSame(1, PostReaction::query()->count());
    }

    public function test_a_reaction_can_be_removed(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();
        $post->reactions()->create(['user_id' => $viewer->id, 'type' => 'like']);

        $this->actingAs($viewer)->deleteJson("/api/posts/{$post->id}/reactions")
            ->assertOk()
            ->assertJsonPath('data.reaction_count', 0)
            ->assertJsonPath('data.viewer_reacted', false);

        $this->assertSame(0, PostReaction::query()->count());
    }

    public function test_cannot_react_to_a_post_you_cannot_view(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->audience(Audience::Followers)->create();

        // The viewer does not follow the author, so the post is not visible.
        $this->actingAs($viewer)->postJson("/api/posts/{$post->id}/reactions")->assertNotFound();
        $this->assertSame(0, PostReaction::query()->count());
    }

    public function test_invalid_reaction_on_a_hidden_post_still_404s_not_422(): void
    {
        // Authorization precedes validation: an invalid reaction type on a post the
        // viewer cannot see must 404 (not 422), matching a missing post id.
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->audience(Audience::Followers)->create();

        $this->actingAs($viewer)->postJson("/api/posts/{$post->id}/reactions", ['type' => 'bogus'])->assertNotFound();
        $this->actingAs($viewer)->postJson('/api/posts/999999/reactions', ['type' => 'bogus'])->assertNotFound();
        $this->assertSame(0, PostReaction::query()->count());
    }

    public function test_post_payload_includes_reaction_state(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();
        $post->reactions()->create(['user_id' => $other->id, 'type' => 'like']);

        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()
            ->assertJsonPath('data.reaction_count', 1)
            ->assertJsonPath('data.viewer_reacted', false);

        $this->actingAs($viewer)->postJson("/api/posts/{$post->id}/reactions");

        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertJsonPath('data.reaction_count', 2)
            ->assertJsonPath('data.viewer_reacted', true);
    }

    public function test_reactions_are_retained_when_the_post_is_soft_deleted(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();
        $post->reactions()->create(['user_id' => $viewer->id, 'type' => 'like']);

        $post->delete();

        $this->assertSame(1, PostReaction::query()->count(), 'reactions are retained for restore');
    }
}
