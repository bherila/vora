<?php

namespace Tests\Feature\Media;

use App\Models\Media;
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

    public function test_dry_run_changes_nothing(): void
    {
        $stale = Media::factory()->pendingUpload()->create(['created_at' => now()->subDays(2)]);

        $this->artisan('media:prune-orphans', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('media', ['id' => $stale->id]);
    }
}
