<?php

namespace Tests\Feature\Library;

use App\Enums\Audience;
use App\Models\Block;
use App\Models\FollowRequest;
use App\Models\LibraryItem;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LibrarySavedPrivacyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{Audience, bool}> */
    public static function mediaAudiences(): array
    {
        return [
            'followers photo' => [Audience::Followers, false],
            'mutuals photo' => [Audience::Mutuals, false],
            'specific photo' => [Audience::SpecificPeople, false],
            'public photo' => [Audience::Everyone, false],
            'followers video' => [Audience::Followers, true],
            'mutuals video' => [Audience::Mutuals, true],
            'specific video' => [Audience::SpecificPeople, true],
        ];
    }

    #[DataProvider('mediaAudiences')]
    public function test_saved_media_cannot_be_reshared_directly_or_through_collections(Audience $audience, bool $video): void
    {
        Storage::fake('photos');
        Storage::fake('s3');
        Storage::fake('hls');
        $owner = User::factory()->approved()->create();
        $saver = User::factory()->approved()->create(['library_enabled' => true]);
        $outsider = User::factory()->approved()->create(['library_enabled' => true]);
        $admin = User::factory()->approved()->admin()->create(['library_enabled' => true]);
        foreach ([[$saver, $owner], [$owner, $saver]] as [$follower, $followed]) {
            FollowRequest::create(['requester_id' => $follower->id, 'recipient_id' => $followed->id, 'status' => FollowRequest::STATUS_ACCEPTED]);
        }
        Block::create(['blocker_id' => $owner->id, 'blocked_user_id' => $outsider->id]);
        $factory = Media::factory()->for($owner)->approved();
        $media = ($video ? $factory->video() : $factory)->create([
            'audience' => $audience, 'disk' => $video ? 's3' : 'photos',
            'reviewed_object_key' => $video ? 'reviewed/video.mp4' : 'reviewed/photo.jpg',
            'thumbnail_key' => 'thumbnail.jpg', 'hls_content_id' => $video ? 'retained-video' : null,
        ]);
        $media->syncAudienceMembers([$saver->id]);
        Storage::disk($media->disk)->put($media->object_key, 'original');
        Storage::disk($media->disk)->put($media->reviewed_object_key, 'reviewed');
        Storage::disk('hls')->put('by-id/retained-video/master.m3u8', "#EXTM3U\nstream/index.m3u8\n");
        $this->partialMock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://example.test/segment.m4s');
        });
        $collection = $saver->libraryCollections()->create(['name' => 'Shared collection']);
        $collection->members()->attach([$outsider->id, $admin->id]);
        $note = $saver->libraryItems()->create(['source' => 'note', 'body' => 'An original, shared note.']);
        $collection->items()->attach($note);
        $ulid = $this->actingAs($saver)->postJson('/api/library/save', [
            'media_ulid' => $media->ulid, 'collection_ulid' => $collection->ulid,
        ])->assertCreated()->json('data.ulid');

        $this->patchJson('/api/library/items/'.$ulid, ['source' => 'upload', 'title' => 'A renamed save'])
            ->assertOk()->assertJsonPath('data.source', 'saved');
        $this->putJson('/api/library/items/'.$ulid.'/sharing', ['user_ids' => [$outsider->id]])->assertUnprocessable();
        $item = LibraryItem::query()->where('ulid', $ulid)->firstOrFail();
        $this->assertSame(0, $item->members()->count());
        // Old or manually inserted grants must not bypass the read-side rule.
        $item->members()->attach([$outsider->id, $admin->id]);
        foreach ([$outsider, $admin] as $viewer) {
            $this->actingAs($viewer)->getJson('/api/library/items?section=shared')->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.ulid', $note->ulid)->assertJsonPath('meta.total', 1);
            $this->getJson('/api/library/items?collection_ulid='.$collection->ulid)->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
            $this->getJson('/api/library/items/'.$ulid)->assertNotFound();
            $this->getJson('/api/library/items/'.$ulid.'/assets/original')->assertNotFound();
            $this->getJson('/api/library/items/'.$ulid.'/assets/thumbnail')->assertNotFound();
            $this->getJson('/api/library/items/'.$ulid.'/hls/master.m3u8')->assertNotFound();
            $this->getJson('/api/library/items/'.$ulid.'/hls/stream/segment.m4s')->assertNotFound();
            $this->postJson('/api/library/save', ['item_ulid' => $ulid])->assertNotFound();
        }

        app(UserAccountService::class)->purge($owner);
        $this->actingAs($saver)->getJson('/api/library/items/'.$ulid)->assertOk()->assertJsonPath('data.available', true);
        if ($video) {
            $this->get('/api/library/items/'.$ulid.'/hls/master.m3u8')->assertOk();
            $this->get('/api/library/items/'.$ulid.'/hls/stream/segment.m4s')->assertRedirect('https://example.test/segment.m4s');
        } else {
            $this->get('/api/library/items/'.$ulid.'/assets/original')->assertOk()->assertStreamedContent('reviewed');
        }
        $saver->forceFill(['library_enabled' => false])->save();
        $this->actingAs($saver)->putJson('/api/library/items/'.$ulid.'/sharing', ['user_ids' => []])->assertOk();
        $this->actingAs($outsider)->getJson('/api/library/items/'.$ulid)->assertNotFound();
    }

    public function test_saved_note_snapshots_are_private_even_when_their_collection_is_shared_later(): void
    {
        $owner = User::factory()->approved()->create();
        $saver = User::factory()->approved()->create(['library_enabled' => true]);
        $outsider = User::factory()->approved()->create(['library_enabled' => true]);
        $original = $owner->libraryItems()->create(['source' => 'note', 'body' => 'Original private text.']);
        $original->members()->attach($saver);
        $ulid = $this->actingAs($saver)->postJson('/api/library/save', ['item_ulid' => $original->ulid])->assertCreated()->json('data.ulid');
        $collection = $saver->libraryCollections()->create(['name' => 'Initially private']);
        $this->postJson('/api/library/items/'.$ulid.'/collections', ['collection_ulid' => $collection->ulid])->assertOk();
        $this->putJson('/api/library/collections/'.$collection->ulid.'/sharing', ['user_ids' => [$outsider->id]])->assertOk();
        $this->putJson('/api/library/items/'.$ulid.'/sharing', ['user_ids' => [$outsider->id]])->assertUnprocessable();
        $this->actingAs($outsider)->getJson('/api/library/items?collection_ulid='.$collection->ulid)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/library/items/'.$ulid)->assertNotFound();
        $this->postJson('/api/library/save', ['item_ulid' => $ulid])->assertNotFound();
        $this->actingAs($saver)->getJson('/api/library/items/'.$ulid)->assertOk()->assertJsonPath('data.body', 'Original private text.');
    }
}
