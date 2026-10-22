<?php

namespace Tests\Feature\Events;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Enums\RestrictionCapability;
use App\Models\Block;
use App\Models\Event;
use App\Models\Favorite;
use App\Models\Media;
use App\Models\User;
use App\Models\UserRestriction;
use App\Policies\MediaPolicy;
use App\Services\Events\EventSettings;
use App\Services\FileStorageService;
use App\Services\Media\AccountStorageUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['instance.modules.events' => true, 'instance.modules.library' => true, 'instance.modules.profiles' => true, 'instance.modules.community' => true]);
        Notification::fake();
        // Exercise explicit admin authorization rather than the user-id-1 bypass.
        User::factory()->admin()->create(['id' => 100]);
        Storage::fake('photos');
        Storage::fake('s3');
        Storage::fake('hls');
    }

    private function account(array $attributes = []): User
    {
        return User::factory()->approved()->create(['library_enabled' => true, ...$attributes]);
    }

    private function event(array $members = [], array $attributes = []): Event
    {
        $event = Event::create(['name' => 'Private shower', 'visibility' => 'specific', 'timezone' => 'UTC', ...$attributes]);
        $event->members()->attach($members);

        return $event;
    }

    private function media(User $owner, Event $event, bool $video = false): Media
    {
        $factory = Media::factory()->for($owner)->approved();
        $media = ($video ? $factory->video() : $factory)->create([
            'purpose' => MediaPurpose::Event, 'event_id' => $event->id, 'audience' => Audience::Everyone,
            'discoverable' => false, 'size_bytes' => 8, 'reviewed_object_key' => 'reviewed/'.uniqid().($video ? '.mp4' : '.jpg'),
            'hls_content_id' => $video ? 'event-content' : null,
        ]);
        Storage::disk($media->disk)->put($media->object_key, 'original');
        Storage::disk($media->disk)->put($media->reviewed_object_key, 'reviewed');
        if ($video) {
            Storage::disk('hls')->put('by-id/event-content/master.m3u8', "#EXTM3U\nstream/index.m3u8");
        }

        return $media;
    }

    public function test_restricted_event_is_hidden_across_lists_favorites_assets_hls_download_and_save(): void
    {
        $owner = $this->account();
        $member = $this->account();
        $outsider = $this->account();
        $event = $this->event([$owner->id, $member->id]);
        $photo = $this->media($owner, $event);
        $video = $this->media($owner, $event, true);
        Favorite::create(['user_id' => $member->id, 'favoritable_type' => $photo->getMorphClass(), 'favoritable_id' => $photo->id]);
        app(EventSettings::class)->setFeaturedCuratorIds([$member->id]);
        $this->actingAs($member)->getJson('/api/events')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.member_ids');
        $this->getJson('/api/events/media?featured=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.ulid', $photo->ulid);
        $this->get('/api/events/media/'.$photo->ulid.'/download')->assertOk()->assertStreamedContent('reviewed');
        $this->get('/api/events/media/'.$video->ulid.'/download')->assertOk()->assertStreamedContent('reviewed');
        $this->get('/api/media/'.$video->id.'/hls/master.m3u8')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/m/'.$photo->ulid)->assertRedirect('/events?media='.$photo->ulid);
        $this->actingAs($outsider)->getJson('/api/events')->assertOk()->assertJsonCount(0, 'data');
        foreach (['', '?featured=1', '?favorites_of='.$member->id, '?attendee_id='.$owner->id, '?event_ulid='.$event->ulid] as $query) {
            $this->getJson('/api/events/media'.$query)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        }
        $this->getJson('/api/events/media/'.$photo->ulid)->assertNotFound();
        $this->get('/api/events/media/'.$photo->ulid.'/download')->assertNotFound();
        $this->get('/api/media/by-ulid/'.$photo->ulid.'/asset/original')->assertNotFound();
        $this->get('/api/media/'.$video->id.'/hls/master.m3u8')->assertNotFound();
        $this->get('/m/'.$photo->ulid)->assertNotFound();
        $this->postJson('/api/library/save', ['media_ulid' => $photo->ulid])->assertNotFound();
        $this->postJson('/api/favorites', ['type' => 'media', 'id' => $photo->id])->assertForbidden();
        $this->getJson('/api/users/'.$member->id.'/favorites')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/users/'.$owner->id.'/media')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/explore')->assertOk()->assertJsonCount(0, 'data');
        $this->assertFalse(Media::query()->viewableBy($outsider)->whereKey($photo->id)->exists());
    }

    public function test_missing_and_hidden_upload_events_have_identical_generic_responses(): void
    {
        config(['app.debug' => false]);
        $owner = $this->account();
        $outsider = $this->account();
        $event = $this->event([$owner->id]);
        $missing = (string) Str::ulid();
        $payload = ['type' => 'photo', 'filename' => 'photo.jpg', 'content_type' => 'image/jpeg', 'size' => 8];
        $this->actingAs($outsider);
        $hidden = $this->postJson('/api/events/'.$event->ulid.'/uploads', $payload)->assertNotFound();
        $unknown = $this->postJson('/api/events/'.$missing.'/uploads', $payload)->assertNotFound();
        $this->assertSame(['message' => 'Not found.'], $hidden->json());
        $this->assertSame($hidden->json(), $unknown->json());
        config(['instance.modules.events' => false]);
        $disabled = $this->actingAs($owner)->postJson('/api/events/'.$event->ulid.'/uploads', $payload)->assertNotFound();
        $disabledUnknown = $this->postJson('/api/events/'.$missing.'/uploads', $payload)->assertNotFound();
        $this->assertSame($disabled->json(), $disabledUnknown->json());
    }

    public function test_saved_reference_survives_event_revocation_without_allowing_library_resharing(): void
    {
        $owner = $this->account();
        $member = $this->account();
        $outsider = $this->account();
        $event = $this->event([$owner->id, $member->id]);
        $media = $this->media($owner, $event);
        $saved = $this->actingAs($member)->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertCreated()->json('data.ulid');
        $upload = $owner->libraryItems()->create(['source' => 'upload', 'media_id' => $media->id]);
        $upload->members()->attach($outsider);
        $this->actingAs($outsider)->getJson('/api/library/items?section=shared')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/library/items/'.$upload->ulid)->assertNotFound();
        $event->members()->detach($member);
        $this->actingAs($member)->getJson('/api/events/media/'.$media->ulid)->assertNotFound();
        $this->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertNotFound();
        $this->get('/api/library/items/'.$saved.'/assets/original')->assertOk()->assertStreamedContent('reviewed');
        config(['instance.modules.events' => false]);
        $owner->delete();
        $this->get('/api/library/items/'.$saved.'/assets/original')->assertOk()->assertStreamedContent('reviewed');
    }

    public function test_admin_event_membership_settings_and_active_attendee_validation(): void
    {
        $admin = User::first();
        $member = $this->account();
        $other = $this->account();
        $inactive = $this->account(['deactivated_at' => now()]);
        $unapproved = User::factory()->create();
        $payload = ['name' => 'Reception', 'visibility' => 'specific', 'member_ids' => [$member->id], 'timezone' => 'America/New_York',
            'starts_at' => '2026-12-01T14:00:00-05:00', 'ends_at' => '2026-12-01T15:00:00-05:00'];
        $this->actingAs($member)->postJson('/api/admin/events', $payload)->assertForbidden();
        $created = $this->actingAs($admin)->postJson('/api/admin/events', $payload)->assertCreated()
            ->assertJsonPath('data.member_ids', [$member->id])->assertJsonPath('data.starts_at', '2026-12-01T19:00:00+00:00')->json('data.ulid');
        $this->patchJson('/api/admin/events/'.$created, ['ends_at' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->patchJson('/api/admin/events/'.$created, ['member_ids' => [$inactive->id]])->assertUnprocessable();
        $this->patchJson('/api/admin/events/'.$created, ['timezone' => 'invalid'])->assertUnprocessable();
        $this->getJson('/api/events')->assertOk()->assertJsonPath('data.0.member_count', 1);
        $this->getJson('/api/events/settings')->assertOk()->assertJsonCount(0, 'data.featured_curators');
        $this->putJson('/api/admin/events/settings', ['featured_curator_ids' => [$member->id]])->assertOk()->assertJsonPath('data.featured_curators.0.id', $member->id);
        $this->getJson('/api/events/settings')->assertOk()->assertJsonPath('data.featured_curators.0.id', $member->id)->assertJsonMissingPath('data.featured_curators.0.email');
        $this->putJson('/api/admin/events/settings', ['featured_curator_ids' => [$other->id]])->assertOk();
        $this->getJson('/api/events/settings')->assertOk()->assertJsonPath('data.featured_curators.0.id', $other->id);
        $this->putJson('/api/admin/events/settings', ['featured_curator_ids' => [$unapproved->id]])->assertUnprocessable();
        $this->actingAs($member)->putJson('/api/admin/events/settings', ['featured_curator_ids' => []])->assertForbidden();
        $attendees = $this->getJson('/api/events/attendees')->assertOk()->json('data');
        $this->assertNotContains($inactive->id, array_column($attendees, 'id'));
        $this->assertNotContains($unapproved->id, array_column($attendees, 'id'));
        $this->actingAs($admin)->patchJson('/api/admin/events/'.$created, ['member_ids' => [$other->id], 'starts_at' => null, 'ends_at' => null])->assertOk();
        $this->actingAs($member)->getJson('/api/events')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_blocked_curators_are_not_named_or_used_for_featured_media(): void
    {
        $owner = $this->account();
        $curator = $this->account();
        $viewer = $this->account();
        $event = $this->event([], ['visibility' => 'everyone']);
        $media = $this->media($owner, $event);
        Favorite::create(['user_id' => $curator->id, 'favoritable_type' => $media->getMorphClass(), 'favoritable_id' => $media->id]);
        app(EventSettings::class)->setFeaturedCuratorIds([$curator->id]);
        Block::create(['blocker_id' => $curator->id, 'blocked_user_id' => $viewer->id]);
        $this->actingAs($viewer)->getJson('/api/events/settings')->assertOk()->assertJsonCount(0, 'data.featured_curators');
        $this->getJson('/api/events/media')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/events/media?featured=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/events/media?favorites_of='.$curator->id)->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs(User::first())->getJson('/api/events/settings')->assertOk()->assertJsonPath('data.featured_curators.0.id', $curator->id);
    }

    public function test_disabling_favorites_hides_event_favorite_surfaces_without_reading_favorite_rows(): void
    {
        $owner = $this->account();
        $viewer = $this->account();
        $event = $this->event([], ['visibility' => 'everyone']);
        $media = $this->media($owner, $event);
        Favorite::create(['user_id' => $viewer->id, 'favoritable_type' => $media->getMorphClass(), 'favoritable_id' => $media->id]);
        app(EventSettings::class)->setFeaturedCuratorIds([$viewer->id]);
        config(['instance.modules.favorites' => false]);
        $favoriteQueries = [];
        DB::listen(static function ($query) use (&$favoriteQueries): void {
            if (str_contains(strtolower($query->sql), 'favorites')) {
                $favoriteQueries[] = $query->sql;
            }
        });
        $this->actingAs($viewer)->getJson('/api/events/media?favorites_of='.$viewer->id)->assertNotFound();
        $this->getJson('/api/events/media?featured=1')->assertNotFound();
        $this->getJson('/api/events/media?featured=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.favorited', false);
        $this->getJson('/api/events/media/'.$media->ulid)->assertOk()->assertJsonPath('data.favorited', false);
        $this->getJson('/api/events/settings')->assertOk()->assertJsonPath('data.viewer_id', $viewer->id)
            ->assertJsonPath('data.is_admin', false)->assertJsonCount(0, 'data.featured_curators');
        $this->actingAs(User::first())->getJson('/api/events/settings')->assertOk()->assertJsonCount(0, 'data.featured_curators');
        $this->putJson('/api/admin/events/settings', ['featured_curator_ids' => []])->assertNotFound();
        $this->assertSame([], $favoriteQueries);
        config(['instance.modules.favorites' => true]);
        $this->actingAs($viewer)->getJson('/api/events/media?featured=1')->assertOk()->assertJsonPath('data.0.favorited', true);
        $this->getJson('/api/events/settings')->assertOk()->assertJsonPath('data.featured_curators.0.id', $viewer->id);
    }

    public function test_event_publication_requires_active_approved_owner_and_viewer(): void
    {
        $owner = $this->account(['deactivated_at' => now()]);
        $viewer = $this->account();
        $event = $this->event([], ['visibility' => 'everyone']);
        $media = $this->media($owner, $event);
        $media->setRelation('user', User::query()->select('id', 'display_name')->findOrFail($owner->id));
        $this->assertFalse((new MediaPolicy)->view($viewer, $media));
        $this->actingAs($viewer)->getJson('/api/events/media')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/events/media/'.$media->ulid)->assertNotFound();
        $this->get('/api/events/media/'.$media->ulid.'/download')->assertNotFound();
        $this->get('/api/media/by-ulid/'.$media->ulid.'/asset/original')->assertNotFound();
        $this->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertNotFound();
        $unapproved = User::factory()->create();
        $this->actingAs($unapproved)->getJson('/api/events')->assertForbidden();
    }

    public function test_event_upload_binding_validation_quota_and_pending_moderation(): void
    {
        $owner = $this->account(['storage_limit_bytes' => 8]);
        $member = $this->account();
        $outsider = $this->account();
        $event = $this->event([$owner->id, $member->id]);
        $this->partialMock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getSignedUploadUrl')->andReturn(['url' => 'https://example.test/upload', 'headers' => []]);
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://example.test/view');
        });
        $payload = ['type' => 'photo', 'filename' => 'photo.jpg', 'content_type' => 'image/jpeg', 'size' => 8, 'announce' => true, 'discoverable' => true];
        $url = '/api/events/'.$event->ulid.'/uploads';
        $this->actingAs($outsider)->postJson($url, $payload)->assertNotFound();
        $this->actingAs($owner)->postJson($url, [...$payload, 'content_type' => 'text/html'])->assertUnprocessable();
        $this->postJson($url, [...$payload, 'purpose' => 'gallery'])->assertUnprocessable();
        $id = $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.purpose', 'event')->assertJsonPath('data.event.ulid', $event->ulid)->json('data.id');
        $media = Media::findOrFail($id);
        $this->assertSame($event->id, $media->event_id);
        $this->assertSame(Audience::Everyone, $media->audience);
        $this->assertFalse($media->discoverable);
        $this->assertFalse($media->announce_on_approval);
        $this->assertNull($media->character_id);
        $this->assertTrue($media->supportsMultipartUpload());
        $this->assertSame(8, app(AccountStorageUsage::class)->forUser($owner)['reserved_bytes']);
        $this->postJson($url, [...$payload, 'size' => 1])->assertUnprocessable()->assertJsonValidationErrors('storage');
        Storage::disk('photos')->put($media->object_key, 'original');
        $this->postJson('/api/media/'.$id.'/complete')->assertOk();
        $this->assertTrue($media->fresh()->isReady());
        $this->assertFalse($media->fresh()->isApprovedContent());
        $this->actingAs($member)->getJson('/api/events/media')->assertOk()->assertJsonCount(0, 'data');
        $restricted = $this->account();
        UserRestriction::factory()->for($restricted)->capability(RestrictionCapability::MediaUpload)->create();
        $this->actingAs($restricted)->postJson($url, $payload)->assertForbidden();
    }

    public function test_event_explicit_upload_cancellation_and_owned_delete_reuse_safe_routes(): void
    {
        $owner = $this->account();
        $event = $this->event([$owner->id]);
        $pending = Media::factory()->for($owner)->pendingUpload()->create(['purpose' => MediaPurpose::Event, 'event_id' => $event->id, 'multipart_expected_size_bytes' => 8]);
        $this->actingAs($owner)->postJson('/api/media/'.$pending->id.'/cancel-upload')->assertOk();
        $this->assertSoftDeleted($pending);
        $this->postJson('/api/media/'.$pending->id.'/complete')->assertNotFound();
        $ready = $this->media($owner, $event);
        $this->postJson('/api/media/'.$ready->id.'/cancel-upload')->assertStatus(409);
        $this->deleteJson('/api/media/'.$ready->id)->assertOk();
        $this->assertSoftDeleted($ready);
    }

    public function test_profile_picture_pending_cancel_is_owned_only_and_does_not_enable_multipart_or_delete_ready_media(): void
    {
        $owner = $this->account();
        $outsider = $this->account();
        $pending = Media::factory()->for($owner)->profilePicture()->pendingUpload()->create(['multipart_expected_size_bytes' => 8]);
        $this->actingAs($outsider)->postJson('/api/media/'.$pending->id.'/cancel-upload')->assertNotFound();
        $this->actingAs($owner)->postJson('/api/media/'.$pending->id.'/multipart/init')->assertNotFound();
        $this->postJson('/api/media/'.$pending->id.'/cancel-upload')->assertOk();
        $this->assertSoftDeleted($pending);
        $this->assertSame(0, app(AccountStorageUsage::class)->forUser($owner)['reserved_bytes']);
        $ready = Media::factory()->for($owner)->profilePicture()->approved()->create();
        $this->postJson('/api/media/'.$ready->id.'/cancel-upload')->assertStatus(409);
        $this->assertFalse($ready->fresh()->trashed());
    }

    public function test_event_default_save_entitlement_does_not_change_vora_premium_gate(): void
    {
        $owner = $this->account();
        $viewer = $this->account(['library_enabled' => false]);
        $event = $this->event([], ['visibility' => 'everyone']);
        $media = $this->media($owner, $event);
        config(['instance.profile' => 'vora']);
        $this->actingAs($viewer)->getJson('/api/events/media/'.$media->ulid)->assertOk()->assertJsonPath('data.can_save_to_library', false);
        $this->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertForbidden();
        config(['instance.profile' => 'event']);
        $this->getJson('/api/events/media/'.$media->ulid)->assertOk()->assertJsonPath('data.can_save_to_library', true);
        $this->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertCreated();
        config(['instance.modules.library' => false]);
        $this->getJson('/api/events/media/'.$media->ulid)->assertOk()->assertJsonPath('data.can_save_to_library', false);
        $this->assertFalse($viewer->canUseLibrary());
    }

    public function test_module_off_denies_publication_but_retains_owner_private_assets(): void
    {
        $owner = $this->account();
        $viewer = $this->account();
        $event = $this->event([], ['visibility' => 'everyone']);
        $media = $this->media($owner, $event);
        config(['instance.modules.events' => false]);
        $this->actingAs($viewer)->getJson('/api/events')->assertNotFound();
        $this->get('/events')->assertNotFound();
        $this->getJson('/api/events/media/'.$media->ulid)->assertNotFound();
        $this->get('/api/media/by-ulid/'.$media->ulid.'/asset/original')->assertNotFound();
        $this->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertNotFound();
        $this->actingAs($owner)->get('/api/media/by-ulid/'.$media->ulid.'/asset/original')->assertOk();
        $this->actingAs(User::first())->getJson('/api/events')->assertNotFound();
    }
}
