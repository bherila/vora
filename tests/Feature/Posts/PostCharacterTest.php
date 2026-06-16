<?php

namespace Tests\Feature\Posts;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostCharacterTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_post_as_their_own_character(): void
    {
        $author = User::factory()->approved()->create();
        $character = $author->characters()->create(['display_name' => 'Sir Reginald']);

        $response = $this->actingAs($author)->postJson('/api/posts', [
            'body' => 'A missive from the realm',
            'character_id' => $character->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.as_character.display_name', 'Sir Reginald')
            // Ownership stays user-level: the author is still the user account.
            ->assertJsonPath('data.author.id', $author->id);

        $this->assertSame($character->id, Post::query()->firstOrFail()->character_id);
    }

    public function test_cannot_post_as_another_users_character(): void
    {
        $author = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $foreign = $other->characters()->create(['display_name' => 'Not yours']);

        $this->actingAs($author)->postJson('/api/posts', [
            'body' => 'Impersonation',
            'character_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrorFor('character_id');

        $this->assertSame(0, Post::query()->count());
    }

    public function test_deleting_the_character_leaves_the_post_under_the_user(): void
    {
        $author = User::factory()->approved()->create();
        $character = $author->characters()->create(['display_name' => 'Ephemeral']);
        $this->actingAs($author)->postJson('/api/posts', [
            'body' => 'persona post',
            'character_id' => $character->id,
        ])->assertCreated();
        $post = Post::query()->firstOrFail();

        $character->delete();

        $this->assertNull($post->fresh()->character_id, 'the link is nulled, not cascaded');
        $this->actingAs($author)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()
            ->assertJsonPath('data.as_character', null)
            ->assertJsonPath('data.author.id', $author->id);
    }
}
