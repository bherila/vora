<?php

namespace Tests\Feature\Events;

use App\Enums\MediaPurpose;
use App\Enums\RestrictionCapability;
use App\Models\Block;
use App\Models\Event;
use App\Models\Favorite;
use App\Models\Media;
use App\Models\User;
use App\Models\UserRestriction;
use App\Services\Events\EventPhotoVersions;
use App\Services\FileStorageService;
use App\Services\Library\LibraryService;
use App\Services\Media\MediaModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventPhotoVersionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $viewer;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        config(['instance.modules.events' => true, 'instance.modules.library' => true, 'instance.modules.favorites' => true]);
        User::factory()->admin()->create(['id' => 100]);
        $this->owner = User::factory()->approved()->create(['library_enabled' => true]);
        $this->viewer = User::factory()->approved()->create(['library_enabled' => true]);
        $this->event = Event::create(['name' => 'Wedding', 'visibility' => 'everyone', 'timezone' => 'UTC']);
        Notification::fake();
        Storage::fake('photos');
        Storage::fake('s3');
        Storage::fake('hls');
        $this->partialMock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://storage.example.test/photo');
            $mock->shouldReceive('getSignedUploadUrl')->andReturn(['url' => 'https://storage.example.test/upload', 'headers' => ['Content-Type' => 'image/jpeg']]);
        });
    }

    private function hash(int $first = 0): string
    {
        return base64_encode(chr($first).str_repeat("\0", 31));
    }

    private function photo(array $attributes = [], array $metadata = []): Media
    {
        $media = Media::factory()->for($this->owner)->approved()->create([
            'purpose' => MediaPurpose::Event, 'event_id' => $this->event->id,
            'discoverable' => false, 'announce_on_approval' => false,
            'perceptual_hash' => $this->hash(), 'size_bytes' => 8,
            ...$attributes,
        ]);
        $media->forceFill(EventPhotoVersions::metadataAttributes($metadata === [] ? ['width' => 1000, 'height' => 1000] : $metadata));
        $media->reviewed_object_key = 'reviewed/'.$media->ulid;
        $media->save();
        Storage::disk($media->disk)->put($media->object_key, 'original');
        Storage::disk($media->disk)->put($media->reviewed_object_key, 'reviewed');

        return $media;
    }

    private function group(Media $media): void
    {
        DB::transaction(function () use ($media): void {
            User::whereKey($media->user_id)->lockForUpdate()->firstOrFail();
            Event::whereKey($media->event_id)->lockForUpdate()->firstOrFail();
            Media::whereKey($media->id)->lockForUpdate()->firstOrFail();
            app(EventPhotoVersions::class)->groupCompletedPhoto($media->refresh());
        });
    }

    public function test_cross_format_near_hashes_group_without_changing_any_original_or_root_row(): void
    {
        $heic = $this->photo(['mime_type' => 'image/heic', 'original_filename' => 'original.HEIC']);
        $jpeg = $this->photo(['mime_type' => 'image/jpeg', 'perceptual_hash' => $this->hash(1)]);
        $png = $this->photo(['mime_type' => 'image/png', 'perceptual_hash' => $this->hash(3)]);
        $this->group($jpeg);
        $this->group($png);
        $this->assertNull($heic->fresh()->photo_group_ulid);
        $this->assertSame($heic->ulid, $jpeg->fresh()->photo_group_ulid);
        $this->assertSame($heic->ulid, $png->fresh()->photo_group_ulid);
        $this->assertSame(3, Media::count());
        foreach ([$heic, $jpeg, $png] as $media) {
            $this->assertSame('original', Storage::disk('photos')->get($media->object_key));
            $this->assertSame('reviewed', Storage::disk('photos')->get($media->reviewed_object_key));
        }
    }

    public function test_indexed_exact_hash_can_find_an_original_older_than_the_recent_near_match_window(): void
    {
        $old = $this->photo();
        Media::factory()->for($this->owner)->approved()->count(EventPhotoVersions::NEAR_CANDIDATE_LIMIT + 1)->create([
            'purpose' => MediaPurpose::Event, 'event_id' => $this->event->id,
            'perceptual_hash' => base64_encode(str_repeat("\xff", 32)),
        ]);
        $latest = $this->photo();
        $this->group($latest);
        $this->assertSame($old->ulid, $latest->fresh()->photo_group_ulid);
    }

    public function test_base64_hashes_are_case_sensitive_even_on_a_case_insensitive_database(): void
    {
        $this->photo();
        $different = $this->photo(['perceptual_hash' => strtolower($this->hash())]);
        $this->group($different);
        $this->assertNull($different->fresh()->photo_group_ulid);
    }

    public function test_preferred_version_ranks_resolution_then_metadata_including_location_not_format_or_file_size(): void
    {
        $heic = $this->photo(['mime_type' => 'image/heic', 'size_bytes' => 9000000], ['width' => 4000, 'height' => 3000]);
        $jpeg = $this->photo(['mime_type' => 'image/jpeg', 'photo_group_ulid' => $heic->ulid, 'size_bytes' => 20000000], ['width' => 2000, 'height' => 1500, 'has_gps' => true, 'has_camera' => true]);
        $this->actingAs($this->viewer)->getJson('/api/events/media')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ulid', $heic->ulid);
        $png = $this->photo(['mime_type' => 'image/png', 'photo_group_ulid' => $heic->ulid, 'size_bytes' => 100], ['width' => 4000, 'height' => 3000, 'has_gps' => true, 'has_capture_time' => true, 'has_camera' => true]);
        $this->getJson('/api/events/media')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ulid', $png->ulid)->assertJsonPath('data.0.photo_versions.visible_count', 3)
            ->assertJsonPath('data.0.photo_versions.preferred_ulid', $png->ulid)->assertJsonPath('data.0.photo_details.has_gps', true)
            ->assertJsonPath('data.0.photo_details.width', 4000);
        $variants = $this->getJson('/api/events/media/'.$jpeg->ulid.'/variants')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('preferred_ulid', $png->ulid)->assertJsonPath('data.0.ulid', $png->ulid);
        $this->assertSame([$png->ulid, $heic->ulid, $jpeg->ulid], array_column($variants->json('data'), 'ulid'));
    }

    public function test_hidden_pending_rejected_blocked_and_other_event_variants_do_not_influence_counts_or_preference(): void
    {
        $root = $this->photo();
        $small = $this->photo(['photo_group_ulid' => $root->ulid], ['width' => 500, 'height' => 500]);
        foreach (['pending', 'rejected'] as $status) {
            $this->photo(['photo_group_ulid' => $root->ulid, 'moderation_status' => $status], ['width' => 9000, 'height' => 9000, 'has_gps' => true]);
        }
        $blocked = User::factory()->approved()->create();
        Block::create(['blocker_id' => $blocked->id, 'blocked_user_id' => $this->viewer->id]);
        $this->photo(['user_id' => $blocked->id, 'photo_group_ulid' => $root->ulid], ['width' => 9000, 'height' => 9000]);
        $unapproved = User::factory()->pendingApproval()->create();
        $this->photo(['user_id' => $unapproved->id, 'photo_group_ulid' => $root->ulid], ['width' => 9000, 'height' => 9000]);
        $other = Event::create(['name' => 'Private shower', 'visibility' => 'specific', 'timezone' => 'UTC']);
        $secret = $this->photo(['event_id' => $other->id, 'photo_group_ulid' => $root->ulid], ['width' => 9000, 'height' => 9000]);
        $this->actingAs($this->viewer)->getJson('/api/events/media')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ulid', $root->ulid)->assertJsonPath('data.0.photo_versions.visible_count', 2);
        $this->getJson('/api/events/media/'.$small->ulid.'/variants')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('preferred_ulid', $root->ulid)->assertJsonMissing(['ulid' => $secret->ulid]);
        $this->getJson('/api/events/media/'.$secret->ulid.'/variants')->assertNotFound();
    }

    public function test_owner_private_pending_root_is_not_collapsed_with_an_approved_variant_or_used_for_preference(): void
    {
        $root = $this->photo(['moderation_status' => 'pending'], ['width' => 9000, 'height' => 9000]);
        $approved = $this->photo(['photo_group_ulid' => $root->ulid]);
        $this->actingAs($this->owner)->getJson('/api/events/media')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/events/media/'.$root->ulid.'/variants')->assertNotFound();
        $this->getJson('/api/events/media/'.$approved->ulid.'/variants')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('preferred_ulid', $approved->ulid)->assertJsonPath('data.0.photo_versions', null);
    }

    public function test_collapsing_happens_after_concrete_favorite_and_attendee_filters(): void
    {
        $other = User::factory()->approved()->create();
        $root = $this->photo();
        $preferred = $this->photo(['user_id' => $other->id, 'photo_group_ulid' => $root->ulid], ['width' => 2000, 'height' => 2000]);
        Favorite::create(['user_id' => $this->viewer->id, 'favoritable_id' => $root->id, 'favoritable_type' => (new Media)->getMorphClass()]);
        $this->actingAs($this->viewer)->getJson('/api/events/media')->assertOk()->assertJsonPath('data.0.ulid', $preferred->ulid);
        $this->getJson('/api/events/media?favorites_of='.$this->viewer->id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ulid', $root->ulid)->assertJsonPath('data.0.favorited', true)
            ->assertJsonPath('data.0.photo_versions.is_preferred', false);
        $this->getJson('/api/events/media?attendee_id='.$this->owner->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.ulid', $root->ulid);
    }

    public function test_grouped_pagination_has_stable_unique_cards_without_loading_all_variants(): void
    {
        $preferred = [];
        for ($i = 0; $i < 25; $i++) {
            $root = $this->photo(['created_at' => now()->subMinutes($i)]);
            $preferred[] = $this->photo(['photo_group_ulid' => $root->ulid, 'created_at' => $root->created_at], ['width' => 2000, 'height' => 2000])->ulid;
        }
        $first = $this->actingAs($this->viewer)->getJson('/api/events/media')->assertOk()->assertJsonCount(24, 'data')->assertJsonPath('meta.total', 25);
        $last = $this->getJson('/api/events/media?page=2')->assertOk()->assertJsonCount(1, 'data');
        $actual = [...array_column($first->json('data'), 'ulid'), ...array_column($last->json('data'), 'ulid')];
        $this->assertSame($preferred, $actual);
        $again = $this->getJson('/api/events/media')->assertOk();
        $this->assertSame(array_column($first->json('data'), 'ulid'), array_column($again->json('data'), 'ulid'));
    }

    public function test_revocation_hides_versions_but_keeps_a_saved_concrete_original_and_its_metadata(): void
    {
        $this->event->update(['visibility' => 'specific']);
        $this->event->members()->attach([$this->owner->id, $this->viewer->id]);
        $root = $this->photo([], ['width' => 1000, 'height' => 1000, 'has_gps' => true]);
        $this->photo(['photo_group_ulid' => $root->ulid], ['width' => 2000, 'height' => 2000]);
        $saved = app(LibraryService::class)->saveMedia($this->viewer, $root->ulid, null);
        $this->event->members()->detach($this->viewer);
        $this->actingAs($this->viewer)->getJson('/api/events/media/'.$root->ulid.'/variants')->assertNotFound();
        $this->getJson('/api/events/media')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/library/items/'.$saved->ulid)->assertOk()->assertJsonPath('data.available', true);
        $this->assertSame($root->id, $saved->fresh()->media_id);
        $this->assertTrue($root->fresh()->photo_metadata['has_gps']);
        $this->assertSame('original', Storage::disk('photos')->get($root->object_key));
    }

    public function test_non_event_and_other_event_media_never_match_even_identical_hashes(): void
    {
        $root = $this->photo();
        foreach ([MediaPurpose::Gallery, MediaPurpose::Library] as $purpose) {
            $private = $this->photo(['purpose' => $purpose, 'event_id' => null]);
            app(EventPhotoVersions::class)->groupCompletedPhoto($private);
            $this->assertNull($private->fresh()->photo_group_ulid);
        }
        $event = Event::create(['name' => 'Another event', 'visibility' => 'everyone', 'timezone' => 'UTC']);
        $other = $this->photo(['event_id' => $event->id]);
        $this->group($other);
        $this->assertNull($other->fresh()->photo_group_ulid);
        $this->assertNull($root->fresh()->photo_group_ulid);
        $this->actingAs($this->viewer)->getJson('/api/events/media?event_ulid='.$this->event->ulid)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_upload_metadata_is_bounded_flags_only_and_resolution_cannot_be_forged_through_rank_fields(): void
    {
        $payload = ['type' => 'photo', 'filename' => 'photo.jpg', 'content_type' => 'image/jpeg', 'size' => 8, 'title' => null,
            'perceptual_hash' => $this->hash(), 'photo_metadata' => ['width' => 4000, 'height' => 3000, 'has_gps' => true, 'has_capture_time' => true, 'has_camera' => true],
            'photo_pixel_area' => 999999999999, 'photo_group_ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'];
        $this->actingAs($this->owner)->postJson('/api/events/'.$this->event->ulid.'/uploads', $payload)->assertCreated();
        $photo = Media::firstOrFail();
        $this->assertSame(12000000, $photo->photo_pixel_area);
        $this->assertSame(7, $photo->photo_metadata_score);
        $this->assertNull($photo->photo_group_ulid);
        $this->assertSame(['has_gps' => true, 'has_capture_time' => true, 'has_camera' => true], $photo->photo_metadata);
        foreach ([['width' => 100001, 'height' => 3000], ['width' => 4000], ['latitude' => 45.1], ['has_camera' => 'private camera name']] as $invalid) {
            $this->postJson('/api/events/'.$this->event->ulid.'/uploads', [...$payload, 'photo_metadata' => $invalid])->assertUnprocessable();
        }
        $this->assertSame(1, Media::count());
    }

    public function test_completion_groups_the_new_original_but_keeps_it_separate_until_reviewed(): void
    {
        $root = $this->photo();
        $this->actingAs($this->owner)->postJson('/api/events/'.$this->event->ulid.'/uploads', [
            'type' => 'photo', 'filename' => 'copy.jpg', 'content_type' => 'image/jpeg', 'size' => 8,
            'perceptual_hash' => $this->hash(), 'photo_metadata' => ['width' => 4000, 'height' => 3000, 'has_gps' => true],
        ])->assertCreated();
        $copy = Media::whereKeyNot($root->id)->firstOrFail();
        Storage::disk('photos')->put($copy->object_key, 'original');
        $this->postJson('/api/media/'.$copy->id.'/complete')->assertOk();
        $this->assertSame($root->ulid, $copy->fresh()->photo_group_ulid);
        $this->getJson('/api/events/media')->assertOk()->assertJsonCount(2, 'data');
        app(MediaModerationService::class)->approve($copy->refresh(), User::findOrFail(100));
        $this->getJson('/api/events/media')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.ulid', $copy->ulid);
        $this->assertSame('original', Storage::disk('photos')->get($root->object_key));
        $this->assertSame('original', Storage::disk('photos')->get($copy->object_key));
    }

    public function test_animated_or_out_of_scope_formats_are_not_grouped_or_collapsed_as_still_photos(): void
    {
        $root = $this->photo();
        foreach (['image/gif', 'image/avif'] as $mime) {
            $photo = $this->photo(['mime_type' => $mime]);
            $this->group($photo);
            $this->assertNull($photo->fresh()->photo_group_ulid);
            // Even a stale legacy group marker must not collapse animation.
            $photo->forceFill(['photo_group_ulid' => $root->ulid])->saveQuietly();
        }
        $this->actingAs($this->viewer)->getJson('/api/events/media')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_media_restriction_and_module_gate_apply_to_variants_and_visible_group_counts(): void
    {
        $root = $this->photo();
        $mine = $this->photo(['user_id' => $this->viewer->id, 'photo_group_ulid' => $root->ulid], ['width' => 500, 'height' => 500]);
        UserRestriction::factory()->for($this->viewer)->capability(RestrictionCapability::MediaView)->create();
        $this->actingAs($this->viewer)->getJson('/api/events/media')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ulid', $mine->ulid)->assertJsonPath('data.0.photo_versions', null);
        $this->getJson('/api/events/media/'.$root->ulid.'/variants')->assertNotFound();
        $this->getJson('/api/events/media/'.$mine->ulid.'/variants')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('preferred_ulid', $mine->ulid)->assertJsonPath('data.0.photo_versions', null);
        config(['instance.modules.events' => false]);
        $this->getJson('/api/events/media/'.$mine->ulid.'/variants')->assertNotFound();
    }
}
