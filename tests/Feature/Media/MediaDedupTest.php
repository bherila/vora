<?php

namespace Tests\Feature\Media;

use App\Enums\MediaType;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\Media\MediaDuplicateService;
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
