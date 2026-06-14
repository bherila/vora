<?php

namespace Tests\Feature\Media;

use App\Enums\ModerationStatus;
use App\Enums\Visibility;
use App\Models\Interest;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_media_with_enum_casts(): void
    {
        $media = Media::factory()->create();

        $this->assertInstanceOf(Visibility::class, $media->visibility);
        $this->assertInstanceOf(ModerationStatus::class, $media->moderation_status);
        $this->assertTrue($media->isPendingReview());
    }

    public function test_interests_relationship(): void
    {
        $media = Media::factory()->create();
        $interest = Interest::query()->create(['name' => 'Hiking']);
        $media->interests()->attach($interest);

        $this->assertTrue($media->interests->contains($interest));
    }

    public function test_visible_to_scope_excludes_unlisted_and_others(): void
    {
        $owner = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();

        Media::factory()->for($owner)->create(['visibility' => Visibility::Users]);
        Media::factory()->for($owner)->unlisted()->create();
        Media::factory()->for($other)->create(['visibility' => Visibility::Users]);

        // Owner sees their own (both) plus the other user's public one = 3.
        $this->assertSame(3, Media::query()->visibleTo($owner)->count());
        // The other user sees only the two public ones (not owner's unlisted).
        $this->assertSame(2, Media::query()->visibleTo($other)->count());
    }

    public function test_moderation_transitions(): void
    {
        $admin = User::factory()->admin()->create();
        $media = Media::factory()->create();

        $media->approve($admin, 'looks fine');

        $this->assertTrue($media->fresh()->isApprovedContent());
        $this->assertSame($admin->id, $media->fresh()->moderated_by_user_id);
        $this->assertNotNull($media->fresh()->moderated_at);
    }
}
