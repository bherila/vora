<?php

namespace Tests\Feature\Admin;

use App\Models\Character;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class AdminDeletedContentTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStorage(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
            $mock->shouldReceive('getSignedDownloadUrl')->andReturn('https://r2.example/download');
            $mock->shouldReceive('deleteFile')->andReturn(true);
        });
    }

    public function test_admin_can_list_restore_and_permanently_delete_media(): void
    {
        $this->fakeStorage();
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->create(['disk' => 'photos']);
        $media->delete();

        $this->actingAs($admin)->getJson('/api/admin/deleted-content?type=media')
            ->assertOk()
            ->assertJsonPath('data.0.id', $media->id)
            ->assertJsonPath('data.0.download_url', 'https://r2.example/download');

        $this->actingAs($admin)->postJson("/api/admin/deleted-content/media/{$media->id}/restore")
            ->assertOk();

        $this->assertFalse(Media::query()->findOrFail($media->id)->trashed());

        Media::query()->findOrFail($media->id)->delete();

        $this->actingAs($admin)->deleteJson("/api/admin/deleted-content/media/{$media->id}")
            ->assertOk();

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_admin_can_restore_deleted_story(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create();
        $story->delete();

        $this->actingAs($admin)->getJson('/api/admin/deleted-content?type=stories')
            ->assertOk()
            ->assertJsonPath('data.0.id', $story->id);

        $this->actingAs($admin)->postJson("/api/admin/deleted-content/stories/{$story->id}/restore")
            ->assertOk();

        $this->assertFalse(Story::query()->findOrFail($story->id)->trashed());
    }

    public function test_admin_can_permanently_delete_deleted_character_and_post(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create();
        $post = Post::factory()->for($owner)->create();
        $character->delete();
        $post->delete();

        $this->actingAs($admin)->getJson('/api/admin/deleted-content?type=characters')
            ->assertOk()
            ->assertJsonPath('data.0.id', $character->id);
        $this->actingAs($admin)->getJson('/api/admin/deleted-content?type=posts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $post->id);

        $this->actingAs($admin)->deleteJson("/api/admin/deleted-content/characters/{$character->id}")
            ->assertOk();
        $this->actingAs($admin)->deleteJson("/api/admin/deleted-content/posts/{$post->id}")
            ->assertOk();

        $this->assertDatabaseMissing('characters', ['id' => $character->id]);
        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
    }
}
