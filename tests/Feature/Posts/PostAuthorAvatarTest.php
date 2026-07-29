<?php

namespace Tests\Feature\Posts;

use App\Models\Character;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\Media\MediaResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostAuthorAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_author_includes_a_signed_avatar_url(): void
    {
        $this->mock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/avatar.jpg');
        });

        // Not user id 1 (treated as admin) — burn an id first.
        User::factory()->approved()->create();
        $author = User::factory()->approved()->create();
        $avatar = Media::factory()->for($author)->profilePicture()->create();
        $author->forceFill(['profile_picture_media_id' => $avatar->id])->save();

        Post::factory()->for($author)->approved()->create();

        $this->actingAs($author)->getJson('/api/feed')
            ->assertOk()
            ->assertJsonPath('data.0.author.id', $author->id)
            ->assertJsonPath('data.0.author.avatar_url', 'https://r2.example/avatar.jpg');
    }

    public function test_feed_author_avatar_url_is_null_without_a_profile_picture(): void
    {
        User::factory()->approved()->create();
        $author = User::factory()->approved()->create();
        Post::factory()->for($author)->approved()->create();

        $this->actingAs($author)->getJson('/api/feed')
            ->assertOk()
            ->assertJsonPath('data.0.author.avatar_url', null);
    }

    public function test_separate_persona_post_avatar_uses_the_visitor_media_contract(): void
    {
        $this->mock(MediaResponseService::class, function ($mock): void {
            $mock->shouldReceive('visitorItem')
                ->once()
                ->withArgs(fn (Media $media, bool $resolveHls): bool => ! $resolveHls)
                ->andReturn([
                    'id' => 44,
                    'thumbnail_url' => '/media-assets/opaque-avatar',
                    'url' => null,
                ]);
        });

        User::factory()->approved()->create(); // spacer so the viewer is not admin
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $avatar = Media::factory()->for($owner)->profilePicture()->approved()->create([
            'original_filename' => 'identifying-owner-avatar.jpg',
        ]);
        $persona = Character::factory()->for($owner)->create([
            'is_linked' => false,
            'profile_picture_media_id' => $avatar->id,
        ]);
        $post = Post::factory()->for($owner)->approved()->create([
            'character_id' => $persona->id,
        ]);

        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()
            ->assertJsonPath('data.as_character.avatar.thumbnail_url', '/media-assets/opaque-avatar')
            ->assertJsonMissing(['original_filename' => 'identifying-owner-avatar.jpg']);
    }
}
