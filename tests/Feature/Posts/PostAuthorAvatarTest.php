<?php

namespace Tests\Feature\Posts;

use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Services\FileStorageService;
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
}
