<?php

namespace Tests\Feature\Media;

use App\Models\Media;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneOrphanMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_stale_pending_uploads_and_their_objects(): void
    {
        Storage::fake('photos');

        $stale = Media::factory()->pendingUpload()->create([
            'disk' => 'photos',
            'created_at' => now()->subDays(2),
        ]);
        Storage::disk('photos')->put($stale->object_key, 'data');

        $fresh = Media::factory()->pendingUpload()->create(['disk' => 'photos']);
        $ready = Media::factory()->create(['disk' => 'photos', 'created_at' => now()->subDays(2)]);

        $this->artisan('media:prune-orphans')->assertSuccessful();

        $this->assertDatabaseMissing('media', ['id' => $stale->id]);
        Storage::disk('photos')->assertMissing($stale->object_key);
        // Recent pending and completed rows are untouched.
        $this->assertDatabaseHas('media', ['id' => $fresh->id]);
        $this->assertDatabaseHas('media', ['id' => $ready->id]);
    }

    public function test_prunes_thumbnail_object_of_stale_upload(): void
    {
        Storage::fake('photos');

        $stale = Media::factory()->pendingUpload()->create([
            'disk' => 'photos',
            'thumbnail_key' => 'uploads/thumbnails/0/thumb.jpg',
            'created_at' => now()->subDays(2),
        ]);
        Storage::disk('photos')->put($stale->object_key, 'data');
        Storage::disk('photos')->put($stale->thumbnail_key, 'thumb');

        $this->artisan('media:prune-orphans')->assertSuccessful();

        $this->assertDatabaseMissing('media', ['id' => $stale->id]);
        Storage::disk('photos')->assertMissing($stale->object_key);
        // The abandoned thumbnail object must not be left behind.
        Storage::disk('photos')->assertMissing($stale->thumbnail_key);
    }

    public function test_prunes_soft_deleted_stale_pending_uploads(): void
    {
        Storage::fake('photos');

        $stale = Media::factory()->pendingUpload()->create([
            'disk' => 'photos',
            'multipart_upload_id' => 'upload-123',
            'multipart_part_size_bytes' => 16 * 1024 * 1024,
            'multipart_initiated_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
        ]);
        $stale->delete();

        $this->mock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('abortMultipartUpload')
                ->once()
                ->with('photos', \Mockery::type('string'), 'upload-123');
            $mock->shouldReceive('fileExists')->once()->andReturnFalse();
        });

        $this->artisan('media:prune-orphans')->assertSuccessful();

        $this->assertDatabaseMissing('media', ['id' => $stale->id]);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $stale = Media::factory()->pendingUpload()->create(['created_at' => now()->subDays(2)]);

        $this->artisan('media:prune-orphans', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('media', ['id' => $stale->id]);
    }
}
