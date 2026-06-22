<?php

namespace Tests\Feature\Media;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class UpdateMediaTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStorage(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
        });
    }

    public function test_owner_can_rename_a_media_item(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create(['title' => 'Typpo']);

        $this->actingAs($owner)->patchJson("/api/media/{$media->id}", ['title' => 'Fixed Title'])
            ->assertOk()->assertJsonPath('data.title', 'Fixed Title');

        $this->assertSame('Fixed Title', $media->fresh()->title);
    }

    public function test_owner_can_change_privacy_on_unassociated_media(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create();

        $this->actingAs($owner)->patchJson("/api/media/{$media->id}", ['audience' => 'followers', 'discoverable' => false])
            ->assertOk();

        $media->refresh();
        $this->assertSame(Audience::Followers, $media->audience);
        $this->assertFalse($media->discoverable);
    }

    public function test_privacy_change_is_rejected_while_a_character_is_attached(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create();
        $media = Media::factory()->for($owner)->approved()->create(['character_id' => $character->id]);

        $this->actingAs($owner)->patchJson("/api/media/{$media->id}", ['audience' => 'followers'])
            ->assertStatus(422);
    }

    public function test_a_stranger_cannot_edit_someone_elses_media(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $stranger = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create(['title' => 'Theirs']);

        $this->actingAs($stranger)->patchJson("/api/media/{$media->id}", ['title' => 'Hijacked'])
            ->assertNotFound();
        $this->assertSame('Theirs', $media->fresh()->title);
    }
}
