<?php

namespace Tests\Feature\Seeders;

use App\Models\Favorite;
use App\Models\Media;
use App\Models\Story;
use App\Models\User;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the demo seeder against schema drift: it must run cleanly against the
 * current migrations and produce the interconnected content the QA walkthrough
 * relies on.
 */
class DemoContentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_interconnected_demo_content(): void
    {
        $this->seed(DemoContentSeeder::class);

        $ada = User::query()->where('email', 'ada@demo.test')->first();
        $this->assertNotNull($ada);
        $this->assertSame(5, User::query()->where('email', 'like', '%@demo.test')->count());

        // Ada has gallery media across audiences plus a processing video.
        $this->assertGreaterThanOrEqual(4, Media::query()->where('user_id', $ada->id)->count());

        // Favorites and at least one in-app notification (the bell is non-empty).
        $this->assertSame(3, Favorite::query()->count());
        $this->assertGreaterThanOrEqual(1, $ada->notifications()->count());

        // A published, approved story exists for others to read.
        $this->assertGreaterThanOrEqual(1, Story::query()->where('status', 'published')->where('moderation_status', 'approved')->count());
    }
}
