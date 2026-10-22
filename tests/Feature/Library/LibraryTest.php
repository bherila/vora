<?php

namespace Tests\Feature\Library;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Enums\RestrictionCapability;
use App\Models\Block;
use App\Models\Character;
use App\Models\LibraryItem;
use App\Models\Media;
use App\Models\User;
use App\Models\UserRestriction;
use App\Services\FileStorageService;
use App\Services\Media\AccountStorageUsage;
use App\Services\Media\MediaModerationService;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LibraryTest extends TestCase
{
    use RefreshDatabase;

    private function account(bool $enabled = true): User
    {
        return User::factory()->approved()->create(['library_enabled' => $enabled]);
    }

    private function photo(User $user): Media
    {
        Storage::fake('photos');
        $media = Media::factory()->for($user)->approved()->create([
            'disk' => 'photos', 'size_bytes' => 8, 'reviewed_object_key' => 'reviewed/photo.jpg',
        ]);
        Storage::disk('photos')->put($media->object_key, 'original');
        Storage::disk('photos')->put($media->reviewed_object_key, 'reviewed');

        return $media;
    }

    private function save(User $user, Media $media): string
    {
        return $this->actingAs($user)->postJson('/api/library/save', ['media_ulid' => $media->ulid])
            ->assertCreated()->json('data.ulid');
    }

    public function test_save_retains_one_private_reference_and_survives_access_revocation_and_soft_account_deletion(): void
    {
        $uploader = $this->account();
        $saver = $this->account();
        $outsider = $this->account();
        $media = $this->photo($uploader);
        $ulid = $this->save($saver, $media);
        $this->assertSame($ulid, $this->save($saver, $media));
        $this->assertSame(1, LibraryItem::query()->count());
        $this->assertCount(2, Storage::disk('photos')->allFiles());

        $media->update(['audience' => Audience::SpecificPeople]);
        $this->actingAs($saver)->getJson("/api/media/by-ulid/{$media->ulid}")->assertNotFound();
        $this->actingAs($saver)->getJson("/api/library/items/{$ulid}")->assertOk()
            ->assertJsonMissingPath('data.media.user_id')->assertJsonMissingPath('data.media.owner')
            ->assertJsonMissingPath('data.media.original_filename')->assertJsonMissingPath('data.media.object_key');
        $this->actingAs($saver)->get("/api/library/items/{$ulid}/assets/original")->assertOk()->assertStreamedContent('reviewed');
        $this->actingAs($outsider)->getJson("/api/library/items/{$ulid}")->assertNotFound();
        $this->actingAs($outsider)->getJson("/api/library/items/{$ulid}/assets/original")->assertNotFound();

        $uploader->delete();
        $this->actingAs($saver)->get("/api/library/items/{$ulid}/assets/original")->assertOk()->assertStreamedContent('reviewed');
    }

    public function test_hard_account_purge_keeps_bytes_until_the_last_independent_save_is_removed(): void
    {
        $uploader = $this->account();
        $first = $this->account();
        $last = $this->account();
        $media = $this->photo($uploader);
        $firstUlid = $this->save($first, $media);
        $lastUlid = $this->save($last, $media);

        app(UserAccountService::class)->purge($uploader);
        $this->assertDatabaseMissing('users', ['id' => $uploader->id]);
        $retained = Media::withTrashed()->findOrFail($media->id);
        $this->assertNull($retained->user_id);
        $this->assertTrue($retained->trashed());
        Storage::disk('photos')->assertExists($media->reviewed_object_key);
        $this->actingAs($first)->get("/api/library/items/{$firstUlid}/assets/original")->assertOk()->assertStreamedContent('reviewed');

        $this->actingAs($first)->deleteJson("/api/library/items/{$firstUlid}")->assertOk();
        Storage::disk('photos')->assertExists($media->reviewed_object_key);
        $this->actingAs($last)->deleteJson("/api/library/items/{$lastUlid}")->assertOk();
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertCount(0, Storage::disk('photos')->allFiles());
    }

    public function test_purging_one_saver_releases_only_its_references(): void
    {
        $uploader = $this->account();
        $first = $this->account();
        $last = $this->account();
        $media = $this->photo($uploader);
        $this->save($first, $media);
        $lastUlid = $this->save($last, $media);
        app(UserAccountService::class)->purge($uploader);
        app(UserAccountService::class)->purge($first);
        $this->actingAs($last)->get("/api/library/items/{$lastUlid}/assets/original")->assertOk();
        app(UserAccountService::class)->purge($last);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertCount(0, Storage::disk('photos')->allFiles());
    }

    public function test_collection_persona_is_only_a_private_reference_and_its_deletion_preserves_content(): void
    {
        Notification::fake();
        $owner = $this->account();
        $target = $this->account();
        $persona = Character::factory()->for($target)->create(['is_linked' => false]);
        $collectionUlid = $this->actingAs($owner)->postJson('/api/library/collections', [
            'name' => 'Received pictures', 'related_persona_ulid' => $persona->ulid,
        ])->assertCreated()->assertJsonMissingPath('data.related_persona.user_id')->json('data.ulid');
        $noteUlid = $this->postJson('/api/library/notes', ['title' => 'Private note', 'body' => 'Received elsewhere.', 'collection_ulid' => $collectionUlid])
            ->assertCreated()->json('data.ulid');
        $media = $this->photo($owner);
        $fileUlid = $this->save($owner, $media);
        $this->actingAs($target)->getJson('/api/library/items?collection_ulid='.$collectionUlid)->assertNotFound();
        $this->getJson('/api/library/items/'.$noteUlid)->assertNotFound();
        Notification::assertNothingSent();

        app(UserAccountService::class)->purge($target);
        $this->actingAs($owner)->getJson('/api/library/items/'.$noteUlid)->assertOk()->assertJsonPath('data.body', 'Received elsewhere.');
        $this->get('/api/library/items/'.$fileUlid.'/assets/original')->assertOk()->assertStreamedContent('reviewed');
        $this->getJson('/api/library/collections')->assertOk()->assertJsonPath('data.0.related_persona', null);
    }

    public function test_named_recipients_can_save_shared_notes_without_mutual_follows_and_keep_their_snapshot(): void
    {
        $owner = $this->account();
        $recipient = $this->account();
        $outsider = $this->account();
        $collection = $owner->libraryCollections()->create(['name' => 'Shared collection']);
        $noteUlid = $this->actingAs($owner)->postJson('/api/library/notes', ['title' => 'A note', 'body' => 'Original text', 'collection_ulid' => $collection->ulid])
            ->assertCreated()->json('data.ulid');
        $this->putJson("/api/library/collections/{$collection->ulid}/sharing", ['user_ids' => [$recipient->id]])->assertOk();
        $this->actingAs($recipient)->getJson('/api/library/items/'.$noteUlid)->assertOk();
        $this->patchJson('/api/library/items/'.$noteUlid, ['title' => 'Tampered'])->assertNotFound();
        $this->deleteJson('/api/library/items/'.$noteUlid)->assertNotFound();
        $savedUlid = $this->postJson('/api/library/save', ['item_ulid' => $noteUlid])->assertCreated()->json('data.ulid');
        $this->postJson('/api/library/save', ['item_ulid' => $noteUlid])->assertCreated()->assertJsonPath('data.ulid', $savedUlid);
        $this->actingAs($outsider)->getJson('/api/library/items/'.$savedUlid)->assertNotFound();

        $this->actingAs($owner)->patchJson('/api/library/items/'.$noteUlid, ['body' => 'Edited text'])->assertOk();
        $this->putJson("/api/library/collections/{$collection->ulid}/sharing", ['user_ids' => []])->assertOk();
        $this->actingAs($recipient)->getJson('/api/library/items/'.$noteUlid)->assertNotFound();
        $this->getJson('/api/library/items/'.$savedUlid)->assertOk()->assertJsonPath('data.body', 'Original text');
        app(UserAccountService::class)->purge($owner);
        $this->getJson('/api/library/items/'.$savedUlid)->assertOk()->assertJsonPath('data.body', 'Original text');
    }

    public function test_collection_removal_only_removes_memberships_and_grants(): void
    {
        $owner = $this->account();
        $recipient = $this->account();
        $collection = $owner->libraryCollections()->create(['name' => 'Collection']);
        $note = $owner->libraryItems()->create(['source' => 'note', 'title' => 'Keep me', 'body' => 'Private text']);
        $collection->items()->attach($note);
        $collection->members()->attach($recipient);
        $this->actingAs($owner)->deleteJson("/api/library/collections/{$collection->ulid}")->assertOk();
        $this->getJson('/api/library/items/'.$note->ulid)->assertOk();
        $this->actingAs($recipient)->getJson('/api/library/items/'.$note->ulid)->assertNotFound();
    }

    public function test_shared_collection_excludes_other_private_collections_and_notes(): void
    {
        $owner = $this->account();
        $recipient = $this->account();
        $private = $owner->libraryCollections()->create(['name' => 'Private title sentinel']);
        $shared = $owner->libraryCollections()->create(['name' => 'Shared']);
        $note = $owner->libraryItems()->create(['source' => 'note', 'body' => 'Shared note']);
        $note->collections()->attach([$private->id, $shared->id]);
        $owner->libraryItems()->create(['source' => 'note', 'body' => 'Private body sentinel']);
        $shared->members()->attach($recipient);

        $this->actingAs($recipient)->getJson('/api/library/items?section=shared')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.collection_ulids', [])->assertDontSee('Private title sentinel')->assertDontSee('Private body sentinel');
        $this->getJson('/api/library/collections')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_usage_counts_saved_bytes_once_per_account_across_collections_and_uploader_erasure(): void
    {
        $uploader = $this->account();
        $saver = $this->account();
        $media = $this->photo($uploader);
        $first = $saver->libraryCollections()->create(['name' => 'One']);
        $second = $saver->libraryCollections()->create(['name' => 'Two']);
        foreach ([$first, $second] as $collection) {
            $this->actingAs($saver)->postJson('/api/library/save', ['media_ulid' => $media->ulid, 'collection_ulid' => $collection->ulid])->assertCreated();
        }
        $this->save($uploader, $media);
        $usage = app(AccountStorageUsage::class);
        $this->assertSame(8, $usage->forUser($saver)['saved_bytes']);
        $this->assertSame(1, $usage->forUser($saver)['active_file_count']);
        $this->assertSame(8, $usage->forUser($uploader)['uploaded_bytes']);
        $this->assertSame(0, $usage->forUser($uploader)['saved_bytes']);
        app(UserAccountService::class)->purge($uploader);
        $this->assertSame(8, $usage->forUser($saver)['total_bytes']);
    }

    public function test_admin_gate_controls_creation_but_disabling_preserves_read_removal_and_revocation(): void
    {
        $user = $this->account(false);
        $uploader = $this->account();
        $recipient = $this->account();
        $media = $this->photo($uploader);
        $this->actingAs($user)->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertForbidden();
        $this->postJson('/api/library/collections', ['name' => 'No'])->assertForbidden();
        $this->postJson('/api/library/notes', ['body' => 'No'])->assertForbidden();
        $this->postJson('/api/library/uploads', [])->assertForbidden();
        $admin = User::factory()->approved()->admin()->create();
        $this->actingAs($admin)->patchJson('/api/admin/users/'.$user->id, ['library_enabled' => true])->assertOk();
        $media = $this->photo($user);
        $ulid = $this->save($user->refresh(), $media);
        $this->actingAs($user)->putJson('/api/library/items/'.$ulid.'/sharing', ['user_ids' => [$recipient->id]])->assertOk();
        $user->forceFill(['library_enabled' => false])->save();
        $this->actingAs($user)->getJson('/api/library/items/'.$ulid)->assertOk();
        $this->putJson('/api/library/items/'.$ulid.'/sharing', ['user_ids' => []])->assertOk();
        $this->deleteJson('/api/library/items/'.$ulid)->assertOk();
    }

    public function test_saves_require_ordinary_access_and_administrative_review_is_not_a_save_grant(): void
    {
        $owner = $this->account();
        $viewer = $this->account();
        $media = $this->photo($owner);
        $media->update(['audience' => Audience::SpecificPeople]);
        $this->actingAs($viewer)->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertNotFound();
        $admin = User::factory()->approved()->admin()->create(['library_enabled' => true]);
        $this->actingAs($admin)->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertNotFound();
        $media->update(['audience' => Audience::Everyone, 'upload_status' => 'pending']);
        $this->actingAs($viewer)->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertNotFound();
    }

    public function test_moderation_takedowns_apply_to_saved_references(): void
    {
        $owner = $this->account();
        $viewer = $this->account();
        $admin = User::factory()->approved()->admin()->create();
        $media = $this->photo($owner);
        $ulid = $this->save($viewer, $media);
        app(MediaModerationService::class)->reject($media, $admin, 'Removed');
        $this->actingAs($viewer)->getJson('/api/library/items/'.$ulid)->assertOk()->assertJsonPath('data.available', false);
        $this->getJson('/api/library/items/'.$ulid.'/assets/original')->assertNotFound();
        $this->deleteJson('/api/library/items/'.$ulid)->assertOk();
    }

    public function test_moderators_can_take_down_retained_media_after_the_uploader_is_purged(): void
    {
        $owner = $this->account();
        $viewer = $this->account();
        $admin = User::factory()->approved()->admin()->create();
        $media = $this->photo($owner);
        $ulid = $this->save($viewer, $media);
        app(UserAccountService::class)->purge($owner);
        $this->partialMock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://example.test/review');
        });

        $this->actingAs($admin)->postJson('/api/admin/media/'.$media->id.'/moderate', ['action' => 'reject', 'notes' => 'Removed'])
            ->assertOk()->assertJsonPath('data.user.id', null);
        $this->actingAs($viewer)->getJson('/api/library/items/'.$ulid)->assertOk()->assertJsonPath('data.available', false);
        $this->getJson('/api/library/items/'.$ulid.'/assets/original')->assertNotFound();
    }

    public function test_admin_removal_of_archived_media_revokes_saved_access_without_discarding_live_references(): void
    {
        $owner = $this->account();
        $viewer = $this->account();
        $admin = User::factory()->approved()->admin()->create();
        $media = $this->photo($owner);
        $ulid = $this->save($viewer, $media);
        app(UserAccountService::class)->purge($owner);
        $this->partialMock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getSignedViewUrl', 'getSignedDownloadUrl')->andReturn('https://example.test/review');
        });

        $this->actingAs($admin)->getJson('/api/admin/deleted-content?type=media')->assertOk()
            ->assertJsonPath('data.0.library_reference_count', 1)->assertJsonPath('data.0.can_restore', false);
        $this->postJson('/api/admin/deleted-content/media/'.$media->id.'/restore')->assertUnprocessable();
        $this->deleteJson('/api/admin/deleted-content/media/'.$media->id)->assertOk();
        $this->assertDatabaseHas('library_items', ['ulid' => $ulid]);
        Storage::disk('photos')->assertExists($media->object_key);
        $this->actingAs($viewer)->getJson('/api/library/items/'.$ulid.'/assets/original')->assertNotFound();
        $this->deleteJson('/api/library/items/'.$ulid)->assertOk();
        Storage::disk('photos')->assertMissing($media->object_key);
    }

    public function test_blocks_hide_shared_library_grants_before_pagination_even_for_admin_recipients(): void
    {
        foreach ([false, true] as $admin) {
            foreach ([false, true] as $viewerBlocksOwner) {
                $owner = $this->account();
                $viewer = $this->account();
                $viewer->forceFill(['is_admin' => $admin])->save();
                $collection = $owner->libraryCollections()->create(['name' => 'Shared']);
                $collection->members()->attach($viewer);
                $note = $owner->libraryItems()->create(['source' => 'note', 'body' => 'Shared text']);
                $collection->items()->attach($note);
                $this->actingAs($viewer)->getJson('/api/library/items?section=shared')->assertJsonCount(1, 'data');
                $savedUlid = $this->postJson('/api/library/save', ['item_ulid' => $note->ulid])->assertCreated()->json('data.ulid');
                Block::create([
                    'blocker_id' => $viewerBlocksOwner ? $viewer->id : $owner->id,
                    'blocked_user_id' => $viewerBlocksOwner ? $owner->id : $viewer->id,
                ]);

                $this->getJson('/api/library/items?section=shared')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
                $this->getJson('/api/library/collections')->assertOk()->assertJsonCount(0, 'data');
                $this->getJson('/api/library/items?collection_ulid='.$collection->ulid)->assertNotFound();
                $this->getJson('/api/library/items/'.$note->ulid)->assertNotFound();
                $this->getJson('/api/library/items/'.$savedUlid)->assertOk()->assertJsonPath('data.body', 'Shared text');
            }
        }
    }

    public function test_media_restrictions_exclude_shared_files_but_preserve_shared_notes_and_accurate_counts(): void
    {
        $owner = $this->account();
        $viewer = $this->account();
        $collection = $owner->libraryCollections()->create(['name' => 'Shared']);
        $collection->members()->attach($viewer);
        $media = $this->photo($owner);
        $file = $owner->libraryItems()->create(['source' => 'upload', 'media_id' => $media->id]);
        $note = $owner->libraryItems()->create(['source' => 'note', 'body' => 'Shared text']);
        $collection->items()->attach([$file->id, $note->id]);
        UserRestriction::factory()->for($viewer)->capability(RestrictionCapability::MediaView)->create();

        $this->actingAs($viewer)->getJson('/api/library/items?section=shared')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.ulid', $note->ulid)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/library/items?collection_ulid='.$collection->ulid)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/library/items/'.$file->ulid)->assertNotFound();
    }

    public function test_saved_video_hls_survives_uploader_purge_and_remains_authorized_per_saver(): void
    {
        Storage::fake('s3');
        Storage::fake('hls');
        $owner = $this->account();
        $viewer = $this->account();
        $outsider = $this->account();
        $media = Media::factory()->for($owner)->video()->approved()->create(['hls_content_id' => 'content-123']);
        Storage::disk('s3')->put($media->object_key, 'video');
        Storage::disk('hls')->put('by-id/content-123/master.m3u8', "#EXTM3U\nstream/index.m3u8");
        $ulid = $this->save($viewer, $media);
        app(UserAccountService::class)->purge($owner);
        $this->actingAs($viewer)->get("/api/library/items/{$ulid}/hls/master.m3u8")->assertOk()
            ->assertSee("/api/library/items/{$ulid}/hls/stream/index.m3u8", false)->assertDontSee('/api/media/');
        $this->assertDatabaseHas('media_playback_audit_logs', [
            'media_id' => $media->id, 'user_id' => $viewer->id, 'action' => 'library_hls_manifest',
        ]);
        $this->actingAs($outsider)->getJson("/api/library/items/{$ulid}/hls/master.m3u8")->assertNotFound();
        $this->actingAs($viewer)->deleteJson('/api/library/items/'.$ulid)->assertOk();
        Storage::disk('hls')->assertMissing('by-id/content-123/master.m3u8');
    }

    public function test_library_uploads_are_private_unannounced_and_separate_from_profile_galleries(): void
    {
        Storage::fake('photos');
        $this->partialMock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getSignedUploadUrl')->andReturn(['url' => 'https://example.test/upload', 'headers' => []]);
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://example.test/view');
        });
        $user = $this->account();
        $response = $this->actingAs($user)->postJson('/api/library/uploads', [
            'type' => 'photo', 'filename' => 'private.jpg', 'content_type' => 'image/jpeg', 'size' => 8,
            'audience' => 'everyone', 'discoverable' => true, 'announce' => true,
        ])->assertCreated();
        $media = Media::query()->findOrFail($response->json('data.id'));
        $this->assertSame(MediaPurpose::Library, $media->purpose);
        $this->assertSame(Audience::SpecificPeople, $media->audience);
        $this->assertFalse($media->discoverable);
        $this->assertFalse($media->announce_on_approval);
        Storage::disk('photos')->put($media->object_key, 'original');
        $this->postJson('/api/media/'.$media->id.'/complete')->assertOk();
        $this->getJson('/api/media')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/library/items')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_guests_cannot_access_library_apis(): void
    {
        $this->getJson('/api/library/items')->assertUnauthorized();
        $this->getJson('/api/library/collections')->assertUnauthorized();
        $this->postJson('/api/library/save', [])->assertUnauthorized();
    }

    public function test_unapproved_shared_uploads_cannot_be_viewed_or_saved_by_recipients(): void
    {
        $owner = $this->account();
        $recipient = $this->account();
        $media = $this->photo($owner);
        $media->forceFill(['moderation_status' => 'pending'])->save();
        $item = $owner->libraryItems()->create(['media_id' => $media->id, 'source' => 'upload']);
        $item->members()->attach($recipient);
        $this->actingAs($recipient)->getJson('/api/library/items/'.$item->ulid)->assertNotFound();
        $this->getJson('/api/library/items?section=shared')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/library/save', ['item_ulid' => $item->ulid])->assertNotFound();
        $media->forceFill(['moderation_status' => 'approved'])->save();
        $this->get('/api/library/items/'.$item->ulid.'/assets/original')->assertOk();
        $this->postJson('/api/library/save', ['item_ulid' => $item->ulid])->assertCreated();
    }

    public function test_deleting_an_upload_keeps_an_existing_library_reference_and_prevents_new_saves(): void
    {
        $owner = $this->account();
        $saver = $this->account();
        $outsider = $this->account();
        $media = $this->photo($owner);
        $ulid = $this->save($saver, $media);
        $this->actingAs($owner)->deleteJson('/api/media/'.$media->id)->assertOk();
        $this->actingAs($saver)->get('/api/library/items/'.$ulid.'/assets/original')->assertOk();
        $this->actingAs($outsider)->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertNotFound();
    }

    public function test_abandoned_library_upload_cleanup_removes_its_incomplete_reference(): void
    {
        Storage::fake('photos');
        $owner = $this->account();
        $media = Media::factory()->for($owner)->pendingUpload()->create([
            'purpose' => MediaPurpose::Library, 'disk' => 'photos', 'created_at' => now()->subDays(2),
        ]);
        $item = $owner->libraryItems()->create(['media_id' => $media->id, 'source' => 'upload']);
        Storage::disk('photos')->put($media->object_key, 'partial');
        $this->artisan('media:prune-orphans')->assertSuccessful();
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertDatabaseMissing('library_items', ['id' => $item->id]);
        Storage::disk('photos')->assertMissing($media->object_key);
    }

    public function test_ordinary_account_updates_cannot_enable_library_storage(): void
    {
        $this->account();
        $user = $this->account(false);
        $this->actingAs($user)->patchJson('/api/account', ['library_enabled' => true])->assertOk();
        $this->assertFalse($user->fresh()->library_enabled);
        $this->patchJson('/api/admin/users/'.$user->id, ['library_enabled' => true])->assertForbidden();
    }

    public function test_persona_picker_respects_discoverability_and_links_unlisted_personas_only_by_known_opaque_id(): void
    {
        $owner = $this->account();
        $viewer = $this->account();
        $unlisted = Character::factory()->for($owner)->create(['display_name' => 'Unlisted sentinel', 'discoverable' => false]);
        $private = Character::factory()->for($owner)->create(['display_name' => 'Private sentinel', 'audience' => Audience::SpecificPeople]);
        $this->actingAs($viewer)->getJson('/api/library/personas')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/library/personas?q='.urlencode('/c/'.$unlisted->ulid))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/library/personas?q='.$private->ulid)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/library/collections', ['name' => 'Link', 'related_persona_ulid' => $unlisted->ulid])->assertCreated();
        $this->postJson('/api/library/collections', ['name' => 'No', 'related_persona_ulid' => $private->ulid])->assertUnprocessable();
        $this->postJson('/api/library/collections', ['name' => 'No', 'related_character_id' => $unlisted->id])->assertUnprocessable();
    }
}
