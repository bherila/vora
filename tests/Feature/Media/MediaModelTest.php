<?php

namespace Tests\Feature\Media;

use App\Enums\Audience;
use App\Enums\ModerationStatus;
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

        $this->assertInstanceOf(Audience::class, $media->audience);
        $this->assertTrue($media->discoverable);
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

    public function test_discoverable_is_orthogonal_to_audience(): void
    {
        $owner = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();

        // Both are the Everyone audience; "unlisted" only flips discoverable off.
        Media::factory()->for($owner)->create();
        Media::factory()->for($owner)->unlisted()->create();

        // viewableBy is audience-based: a link-only (unlisted) item is still the
        // Everyone audience, so any viewer is authorized to see both.
        $this->assertSame(2, Media::query()->viewableBy($other)->count());
        // Discovery is the separate axis: only the listed item shows up.
        $this->assertSame(1, Media::query()->discoverable()->count());
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
