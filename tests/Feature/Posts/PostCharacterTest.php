<?php

namespace Tests\Feature\Posts;

use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Services\FileStorageService;
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
            ->assertJsonPath('data.as_character.ulid', $character->ulid)
            // Byline inversion: a persona post is presented by the persona alone.
            // The human author is omitted from the payload (ownership stays
            // user-level on post.user_id; admin views still resolve the human).
            ->assertJsonPath('data.author', null);

        $this->assertSame($character->id, Post::query()->firstOrFail()->character_id);
        $this->assertSame($author->id, Post::query()->firstOrFail()->user_id);
    }

    public function test_persona_avatar_is_returned_as_a_loadable_signed_url(): void
    {
        $this->mock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/avatar.jpg');
        });

        $author = User::factory()->approved()->create();
        $avatar = Media::factory()->for($author)->profilePicture()->create();
        $character = $author->characters()->create([
            'display_name' => 'Knight',
            'profile_picture_media_id' => $avatar->id,
        ]);

        // A profile-picture ULID is not resolvable via the gallery media endpoint,
        // so the persona avatar must come back as a signed, loadable URL.
        $this->actingAs($author)->postJson('/api/posts', ['body' => 'hark', 'character_id' => $character->id])
            ->assertCreated()
            ->assertJsonPath('data.as_character.avatar.url', 'https://r2.example/avatar.jpg');
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

    public function test_deleting_the_character_hides_the_post_persona_until_restore(): void
    {
        $author = User::factory()->approved()->create();
        $character = $author->characters()->create(['display_name' => 'Ephemeral']);
        $this->actingAs($author)->postJson('/api/posts', [
            'body' => 'persona post',
            'character_id' => $character->id,
        ])->assertCreated();
        $post = Post::query()->firstOrFail();

        $character->delete();

        $this->assertSame($character->id, $post->fresh()->character_id, 'the link is retained for restore');
        // The persona is hidden while deleted — and the byline must NOT fall
        // back to the human author, so the post presents no identity at all.
        $this->actingAs($author)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()
            ->assertJsonPath('data.as_character', null)
            ->assertJsonPath('data.author', null);
    }
}
