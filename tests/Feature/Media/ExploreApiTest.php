<?php

namespace Tests\Feature\Media;

use App\Models\Interest;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ExploreApiTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStorage(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
        });
    }

    public function test_explore_lists_only_approved_visible_media(): void
    {
        $this->fakeStorage();
        // Create the uploader first so the viewer is not user id 1, which the app
        // treats as an admin (admins bypass the visibility filter).
        $other = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();

        $approved = Media::factory()->for($other)->approved()->create(['title' => 'Visible']);
        // Pending (not yet reviewed) — must not appear.
        Media::factory()->for($other)->create(['title' => 'Pending']);
        // Rejected — must not appear.
        Media::factory()->for($other)->rejected()->create(['title' => 'Rejected']);
        // Approved but unlisted — excluded from discovery listings.
        Media::factory()->for($other)->approved()->unlisted()->create(['title' => 'Unlisted']);

        $this->actingAs($viewer)->getJson('/api/explore')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->id)
            ->assertJsonPath('data.0.title', 'Visible')
            // Discovery payloads never leak moderation state.
            ->assertJsonMissingPath('data.0.moderation_status');
    }

    public function test_explore_excludes_viewers_own_unlisted_media(): void
    {
        $this->fakeStorage();
        $other = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();

        // The viewer's own approved-but-unlisted upload must NOT surface on the
        // discovery surface, even though it belongs to them.
        Media::factory()->for($viewer)->approved()->unlisted()->create(['title' => 'My secret']);
        $listed = Media::factory()->for($other)->approved()->create(['title' => 'Visible']);

        $this->actingAs($viewer)->getJson('/api/explore')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $listed->id);
    }

    public function test_explore_excludes_unlisted_media_for_admin_viewer(): void
    {
        $this->fakeStorage();
        $admin = User::factory()->admin()->create();
        $other = User::factory()->approved()->create();

        // Admins bypass visibility everywhere else, but discovery is a public
        // surface: unlisted content must stay out of it for them too.
        Media::factory()->for($other)->approved()->unlisted()->create(['title' => 'Unlisted']);
        $listed = Media::factory()->for($other)->approved()->create(['title' => 'Visible']);

        $this->actingAs($admin)->getJson('/api/explore')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $listed->id);
    }

    public function test_explore_never_exposes_pending_uploads(): void
    {
        $this->fakeStorage();
        $viewer = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();

        // Approved row whose object never finished uploading.
        Media::factory()->for($other)->approved()->pendingUpload()->create();

        $this->actingAs($viewer)->getJson('/api/explore')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_explore_filters_by_type(): void
    {
        $this->fakeStorage();
        $viewer = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();

        $video = Media::factory()->for($other)->approved()->video()->create();
        Media::factory()->for($other)->approved()->create(); // photo

        $this->actingAs($viewer)->getJson('/api/explore?type=video')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $video->id)
            ->assertJsonPath('data.0.type', 'video');
    }

    public function test_explore_video_listing_does_not_expose_original_signed_url(): void
    {
        $this->fakeStorage();
        $viewer = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();

        $video = Media::factory()->for($other)->approved()->video()->create();

        $this->actingAs($viewer)->getJson('/api/explore?type=video')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $video->id)
            ->assertJsonPath('data.0.url', null);
    }

    public function test_explore_filters_by_interest(): void
    {
        $this->fakeStorage();
        $viewer = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $travel = Interest::query()->create(['name' => 'Travel']);
        $food = Interest::query()->create(['name' => 'Food']);

        $tagged = Media::factory()->for($other)->approved()->create();
        $tagged->interests()->sync([$travel->id]);
        $otherTagged = Media::factory()->for($other)->approved()->create();
        $otherTagged->interests()->sync([$food->id]);

        $this->actingAs($viewer)->getJson('/api/explore?interest_ids[]='.$travel->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tagged->id);
    }

    public function test_owner_library_shares_the_same_type_filter(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();
        Media::factory()->for($user)->create(); // photo
        $video = Media::factory()->for($user)->video()->create();

        $this->actingAs($user)->getJson('/api/media?type=video')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $video->id);
    }

    public function test_explore_requires_approval(): void
    {
        $pending = User::factory()->create(); // not approved

        $this->actingAs($pending)->getJson('/api/explore')
            ->assertForbidden();
    }
}
