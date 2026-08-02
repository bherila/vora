<?php

namespace Tests\Feature\Media;

use App\Enums\RestrictionCapability;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Models\UserRestriction;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class MediaRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private function restrict(User $user, RestrictionCapability $capability): UserRestriction
    {
        return UserRestriction::factory()->for($user)->capability($capability)->create([
            'reason' => 'Capability restricted for testing.',
        ]);
    }

    private function fakeViewUrls(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
        });
    }

    public function test_media_upload_restriction_blocks_every_upload_entrypoint_and_preserves_existing_media(): void
    {
        User::factory()->admin()->create(['id' => 1]);
        $user = User::factory()->approved()->create();
        $character = Character::factory()->for($user)->create();
        $gallery = Media::factory()->for($user)->pendingUpload()->video()->create();
        $profilePicture = Media::factory()->for($user)->pendingUpload()->profilePicture()->create();
        $characterPicture = Media::factory()->for($user)->pendingUpload()->profilePicture()->create([
            'character_id' => $character->id,
        ]);
        $existingCount = Media::query()->where('user_id', $user->id)->count();
        $this->restrict($user, RestrictionCapability::MediaUpload);

        $this->actingAs($user)->postJson('/api/media', [])->assertForbidden();
        $this->actingAs($user)->postJson("/api/media/{$gallery->id}/multipart/init")->assertForbidden();
        $this->actingAs($user)->postJson("/api/media/{$gallery->id}/multipart/parts", [])->assertForbidden();
        $this->actingAs($user)->postJson("/api/media/{$gallery->id}/multipart/complete", [])->assertForbidden();
        $this->actingAs($user)->postJson("/api/media/{$gallery->id}/complete")->assertForbidden();
        $this->actingAs($user)->postJson('/api/account/profile-picture', [])->assertForbidden();
        $this->actingAs($user)->postJson("/api/account/profile-picture/{$profilePicture->id}/complete")->assertForbidden();
        $this->actingAs($user)->postJson("/api/characters/{$character->id}/profile-picture", [])->assertForbidden();
        $this->actingAs($user)->postJson("/api/characters/{$character->id}/profile-picture/{$characterPicture->id}/complete")->assertForbidden();

        $this->assertSame($existingCount, Media::query()->where('user_id', $user->id)->count());
        $this->actingAs($user)->getJson('/api/media')->assertOk()->assertJsonCount($existingCount - 2, 'data');
    }

    public function test_media_view_restriction_filters_explore_and_profile_listings_but_keeps_own_media(): void
    {
        $this->fakeViewUrls();
        User::factory()->admin()->create(['id' => 1]);
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $foreign = Media::factory()->for($owner)->approved()->create();
        $own = Media::factory()->for($viewer)->approved()->create();
        $this->restrict($viewer, RestrictionCapability::MediaView);

        $exploreIds = collect($this->actingAs($viewer)->getJson('/api/explore')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($exploreIds->contains($own->id));
        $this->assertFalse($exploreIds->contains($foreign->id));

        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}/media")
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->actingAs($viewer)->getJson('/api/media')
            ->assertOk()
            ->assertJsonFragment(['id' => $own->id]);
    }

    public function test_media_view_restriction_blocks_direct_asset_and_hls_access_but_own_media_still_plays(): void
    {
        User::factory()->admin()->create(['id' => 1]);
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $photo = Media::factory()->for($owner)->approved()->create();
        $video = Media::factory()->for($owner)->approved()->video()->create(['disk' => 's3']);
        $ownVideo = Media::factory()->for($viewer)->approved()->video()->create(['disk' => 's3']);
        $this->restrict($viewer, RestrictionCapability::MediaView);

        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$photo->ulid}")->assertForbidden();
        $this->actingAs($viewer)->get("/m/{$photo->ulid}")->assertForbidden();
        $this->actingAs($viewer)->get("/api/media/by-ulid/{$photo->ulid}/asset/original")->assertForbidden();
        $this->actingAs($viewer)->get("/api/media/{$video->id}/hls/master.m3u8")->assertForbidden();

        Storage::fake('hls');
        Storage::disk('hls')->put('mappings/'.$ownVideo->object_key.'.json', json_encode(['contentId' => 'sha256:own']));
        Storage::disk('hls')->put('by-id/sha256:own/master.m3u8', "#EXTM3U\n720p/index.m3u8\n");
        $this->partialMock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/segment');
        });

        $this->actingAs($viewer)->getJson("/api/media/{$ownVideo->id}")->assertOk();
        $this->actingAs($viewer)->get("/api/media/{$ownVideo->id}/hls/master.m3u8")->assertOk();
        $this->actingAs($viewer)->getJson('/api/account/export')
            ->assertOk()
            ->assertJsonFragment(['ulid' => $ownVideo->ulid]);
    }

    public function test_media_view_filter_runs_before_feed_cursor_pagination(): void
    {
        User::factory()->admin()->create(['id' => 1]);
        config(['media.page_size' => 3]);
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        FollowRequest::query()->create([
            'requester_id' => $viewer->id,
            'recipient_id' => $author->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        Post::factory()->for($author)->approved()->count(4)->create();
        $media = Media::factory()->for($author)->approved()->count(2)->create();
        foreach ($media as $item) {
            $post = Post::factory()->for($author)->approved()->create();
            $post->attachments()->create([
                'attachable_type' => $item->getMorphClass(),
                'attachable_id' => $item->id,
            ]);
        }
        $this->restrict($viewer, RestrictionCapability::MediaView);

        $response = $this->actingAs($viewer)->getJson('/api/feed')->assertOk();
        $response->assertJsonCount(3, 'data');
        $this->assertNotNull($response->json('next_cursor'));
        $this->assertSame([], collect($response->json('data'))->flatMap(fn (array $post): array => $post['attachments'])->all());
    }
}
