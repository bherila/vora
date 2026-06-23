<?php

namespace Tests\Feature\Media;

use App\Enums\MediaType;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\Media\AdminMediaResponseService;
use App\Services\Media\MediaDuplicateService;
use App\Services\Media\PdqImageService;
use App\Support\PerceptualHash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class MediaDedupTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStorage(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedUploadUrl')->andReturn([
                'url' => 'https://r2.example/put',
                'headers' => ['Content-Type' => 'image/jpeg'],
            ]);
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
            $mock->shouldReceive('fileExists')->andReturn(true);
            $mock->shouldReceive('getFileSize')->andReturn(2048);
            $mock->shouldReceive('deleteFile')->andReturn(true);
        });
    }

    private function base64Hash(string $bytes): string
    {
        return base64_encode($bytes);
    }

    public function test_exact_duplicate_upload_is_blocked_for_the_owner(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();
        $hash = str_repeat('a', 64);
        Media::factory()->for($user)->approved()->create(['file_hash' => $hash, 'upload_status' => 'ready']);

        $this->actingAs($user)->postJson('/api/media', [
            'type' => 'photo',
            'filename' => 'again.jpg',
            'content_type' => 'image/jpeg',
            'audience' => 'everyone',
            'file_hash' => $hash,
        ])->assertStatus(409)->assertJsonPath('success', false);

        // No second pending row was created.
        $this->assertSame(1, Media::query()->where('user_id', $user->id)->count());
    }

    public function test_exact_duplicate_check_is_scoped_to_the_owner(): void
    {
        $this->fakeStorage();
        $owner = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $hash = str_repeat('b', 64);
        Media::factory()->for($owner)->approved()->create(['file_hash' => $hash, 'upload_status' => 'ready']);

        // A different user uploading the same bytes is not blocked.
        $this->actingAs($other)->postJson('/api/media', [
            'type' => 'photo',
            'filename' => 'mine.jpg',
            'content_type' => 'image/jpeg',
            'audience' => 'everyone',
            'file_hash' => $hash,
        ])->assertCreated();
    }

    public function test_near_duplicate_photo_is_flagged(): void
    {
        $user = User::factory()->approved()->create();
        $service = app(MediaDuplicateService::class);

        // Two 32-byte hashes differing in a single bit (Hamming distance 1).
        $original = $this->base64Hash(str_repeat("\x00", 32));
        $nearBytes = str_repeat("\x00", 31)."\x01";
        $near = $this->base64Hash($nearBytes);

        $this->assertSame(1, PerceptualHash::hammingDistance($original, $near));

        $existing = Media::factory()->for($user)->approved()->create([
            'perceptual_hash' => $original,
            'upload_status' => 'ready',
        ]);
        $fresh = Media::factory()->for($user)->create([
            'type' => MediaType::Photo,
            'perceptual_hash' => $near,
            'upload_status' => 'pending',
        ]);

        $service->flagPerceptualDuplicate($fresh);

        $this->assertSame($existing->id, $fresh->fresh()->duplicate_of_media_id);
    }

    public function test_distant_photo_is_not_flagged(): void
    {
        $user = User::factory()->approved()->create();
        $service = app(MediaDuplicateService::class);

        $original = $this->base64Hash(str_repeat("\x00", 32));
        // Every bit flipped: Hamming distance 256, far beyond the threshold.
        $far = $this->base64Hash(str_repeat("\xFF", 32));

        Media::factory()->for($user)->approved()->create(['perceptual_hash' => $original, 'upload_status' => 'ready']);
        $fresh = Media::factory()->for($user)->create([
            'type' => MediaType::Photo,
            'perceptual_hash' => $far,
            'upload_status' => 'pending',
        ]);

        $service->flagPerceptualDuplicate($fresh);

        $this->assertNull($fresh->fresh()->duplicate_of_media_id);
    }

    public function test_pdq_hamming_distance_over_hex_hashes(): void
    {
        $zero = str_repeat('0', 64);                 // 256 zero bits
        $oneBit = str_repeat('0', 63).'1';           // a single low bit set
        $allOnes = str_repeat('f', 64);              // every bit set

        $this->assertSame(1, PerceptualHash::hammingDistanceHex($zero, $oneBit));
        $this->assertSame(256, PerceptualHash::hammingDistanceHex($zero, $allOnes));
        // Malformed / mismatched inputs are not comparable.
        $this->assertNull(PerceptualHash::hammingDistanceHex($zero, 'zz'));
        $this->assertNull(PerceptualHash::hammingDistanceHex($zero, str_repeat('0', 62)));
        $this->assertNull(PerceptualHash::hammingDistanceHex($zero, null));
    }

    public function test_near_duplicate_photo_is_flagged_by_pdq(): void
    {
        $user = User::factory()->approved()->create();
        $service = app(MediaDuplicateService::class);

        $original = str_repeat('0', 64);
        $near = str_repeat('0', 63).'1'; // Hamming distance 1, well within threshold.

        $existing = Media::factory()->for($user)->approved()->create([
            'pdq_hash' => $original,
            'upload_status' => 'ready',
        ]);
        $fresh = Media::factory()->for($user)->create([
            'type' => MediaType::Photo,
            'pdq_hash' => $near,
            'upload_status' => 'ready',
        ]);

        $service->flagPdqDuplicate($fresh);

        $this->assertSame($existing->id, $fresh->fresh()->duplicate_of_media_id);
    }

    public function test_distant_photo_is_not_flagged_by_pdq(): void
    {
        $user = User::factory()->approved()->create();
        $service = app(MediaDuplicateService::class);

        Media::factory()->for($user)->approved()->create([
            'pdq_hash' => str_repeat('0', 64),
            'upload_status' => 'ready',
        ]);
        $fresh = Media::factory()->for($user)->create([
            'type' => MediaType::Photo,
            'pdq_hash' => str_repeat('f', 64), // distance 256, far beyond threshold
            'upload_status' => 'ready',
        ]);

        $service->flagPdqDuplicate($fresh);

        $this->assertNull($fresh->fresh()->duplicate_of_media_id);
    }

    public function test_pdq_flagging_is_scoped_to_the_owner(): void
    {
        $owner = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $service = app(MediaDuplicateService::class);

        Media::factory()->for($other)->approved()->create([
            'pdq_hash' => str_repeat('0', 64),
            'upload_status' => 'ready',
        ]);
        $fresh = Media::factory()->for($owner)->create([
            'type' => MediaType::Photo,
            'pdq_hash' => str_repeat('0', 64), // identical, but a different owner
            'upload_status' => 'ready',
        ]);

        $service->flagPdqDuplicate($fresh);

        $this->assertNull($fresh->fresh()->duplicate_of_media_id);
    }

    public function test_pdq_image_service_resolves_hash_and_flags_duplicate(): void
    {
        config(['filesystems.disks.hls.bucket' => 'hls-test']);
        $user = User::factory()->approved()->create();

        $existing = Media::factory()->for($user)->approved()->create([
            'pdq_hash' => str_repeat('0', 64),
            'upload_status' => 'ready',
        ]);
        $fresh = Media::factory()->for($user)->approved()->create([
            'type' => MediaType::Photo,
            'upload_status' => 'ready',
        ]);

        $mapping = json_encode(['pdqHash' => str_repeat('0', 63).'1', 'quality' => 100]);
        $this->mock(FileStorageService::class, function (MockInterface $mock) use ($fresh, $mapping): void {
            $mock->shouldReceive('get')
                ->with('hls', 'image-mappings/'.$fresh->playbackObjectKey().'.json')
                ->andReturn($mapping);
        });

        $resolved = app(PdqImageService::class)->ensureResolved($fresh);

        $this->assertTrue($resolved);
        $fresh->refresh();
        $this->assertSame(str_repeat('0', 63).'1', $fresh->pdq_hash);
        $this->assertNotNull($fresh->pdq_checked_at);
        $this->assertSame($existing->id, $fresh->duplicate_of_media_id);
    }

    public function test_pdq_image_service_marks_checked_when_no_mapping_yet(): void
    {
        config(['filesystems.disks.hls.bucket' => 'hls-test']);
        $user = User::factory()->approved()->create();
        $fresh = Media::factory()->for($user)->approved()->create([
            'type' => MediaType::Photo,
            'upload_status' => 'ready',
        ]);

        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('get')->andReturn(null);
        });

        $resolved = app(PdqImageService::class)->ensureResolved($fresh);

        $this->assertFalse($resolved);
        $fresh->refresh();
        $this->assertNull($fresh->pdq_hash);
        $this->assertNotNull($fresh->pdq_checked_at);
    }

    public function test_pdq_image_service_is_noop_when_results_disk_unconfigured(): void
    {
        // No filesystems.disks.hls.bucket configured: ensureResolved must not
        // touch storage at all (a deployment with photos but no HLS/PDQ).
        config(['filesystems.disks.hls.bucket' => null]);
        $user = User::factory()->approved()->create();
        $fresh = Media::factory()->for($user)->approved()->create([
            'type' => MediaType::Photo,
            'upload_status' => 'ready',
        ]);

        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('get')->never();
            $mock->shouldReceive('deleteFile')->never();
        });

        $this->assertFalse(app(PdqImageService::class)->ensureResolved($fresh));
        app(PdqImageService::class)->deleteMapping($fresh); // must not throw or hit storage
        $this->assertNull($fresh->fresh()->pdq_hash);
    }

    public function test_pdq_flag_direction_is_independent_of_resolution_order(): void
    {
        $user = User::factory()->approved()->create();
        $service = app(MediaDuplicateService::class);

        // Older photo (lower id) and a near-identical newer one.
        $older = Media::factory()->for($user)->approved()->create([
            'pdq_hash' => str_repeat('0', 64), 'upload_status' => 'ready',
        ]);
        $newer = Media::factory()->for($user)->approved()->create([
            'pdq_hash' => str_repeat('0', 63).'1', 'upload_status' => 'ready',
        ]);

        // Flag from the OLDER photo's perspective (its hash resolved last). The
        // newer one must be flagged as the duplicate — never the original.
        $service->flagPdqDuplicate($older->fresh());

        $this->assertNull($older->fresh()->duplicate_of_media_id);
        $this->assertSame($older->id, $newer->fresh()->duplicate_of_media_id);
    }

    public function test_admin_page_reflects_a_duplicate_flagged_on_another_row_during_resolution(): void
    {
        // Resolving one photo's PDQ hash can flag a *different* photo in the same
        // admin page as its duplicate. That sibling row is already loaded in the
        // paginator, so its in-memory copy must be reconciled before serialization
        // or the response omits the freshly set flag.
        config(['filesystems.disks.hls.bucket' => 'hls-test']);
        $user = User::factory()->approved()->create();

        // Older photo has no hash yet; its worker mapping has just landed. The newer
        // photo's hash resolved in an earlier request. Newest-first ordering means
        // the newer (already-resolved) row is serialized before the older one runs.
        $older = Media::factory()->for($user)->approved()->create([
            'type' => MediaType::Photo,
            'upload_status' => 'ready',
        ]);
        $newer = Media::factory()->for($user)->approved()->create([
            'type' => MediaType::Photo,
            'pdq_hash' => str_repeat('0', 64),
            'upload_status' => 'ready',
        ]);

        $mapping = json_encode(['pdqHash' => str_repeat('0', 63).'1', 'quality' => 100]); // distance 1
        $this->mock(FileStorageService::class, function (MockInterface $mock) use ($older, $mapping): void {
            $mock->shouldReceive('get')
                ->with('hls', 'image-mappings/'.$older->playbackObjectKey().'.json')
                ->andReturn($mapping);
            $mock->shouldReceive('get')->andReturn(null);
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
            $mock->shouldReceive('getSignedDownloadUrl')->andReturn('https://r2.example/download');
        });

        $paginator = Media::query()->where('user_id', $user->id)->orderByDesc('id')->paginate(20);
        $page = app(AdminMediaResponseService::class)->page($paginator);

        $rows = collect($page['data'])->keyBy('id');
        // The newer row was flagged as a duplicate of the older one while the older
        // row resolved — the response must show that, not the stale null.
        $this->assertSame($older->id, $rows[$newer->id]['duplicate_of_media_id']);
        $this->assertNull($rows[$older->id]['duplicate_of_media_id']);
    }

    public function test_video_sharing_a_content_id_is_flagged(): void
    {
        $user = User::factory()->approved()->create();
        $service = app(MediaDuplicateService::class);

        $first = Media::factory()->for($user)->video()->approved()->create(['hls_content_id' => 'abc123']);
        $second = Media::factory()->for($user)->video()->create(['hls_content_id' => 'abc123']);

        $service->flagContentDuplicate($second->fresh());

        $this->assertSame($first->id, $second->fresh()->duplicate_of_media_id);
    }
}
