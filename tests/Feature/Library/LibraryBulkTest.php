<?php

namespace Tests\Feature\Library;

use App\Models\LibraryItem;
use App\Models\Media;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LibraryBulkTest extends TestCase
{
    use RefreshDatabase;

    private function account(bool $enabled = true): User
    {
        return User::factory()->approved()->create(['library_enabled' => $enabled]);
    }

    private function note(User $user, string $source = 'note'): LibraryItem
    {
        return $user->libraryItems()->create(['source' => $source, 'body' => 'Private text']);
    }

    public function test_add_is_idempotent_and_move_preserves_other_memberships(): void
    {
        $owner = $this->account();
        $source = $owner->libraryCollections()->create(['name' => 'Source']);
        $other = $owner->libraryCollections()->create(['name' => 'Other']);
        $target = $owner->libraryCollections()->create(['name' => 'Target']);
        $first = $this->note($owner);
        $last = $this->note($owner);
        $ulids = [$first->ulid, $last->ulid];
        $source->items()->attach([$first->id, $last->id]);
        $other->items()->attach($first);

        $this->actingAs($owner)->postJson('/api/library/items/bulk', [
            'action' => 'move', 'item_ulids' => $ulids, 'collection_ulid' => $target->ulid, 'source_collection_ulid' => $source->ulid,
        ])->assertOk()->assertJsonPath('data.affected_count', 2);
        $this->assertSame(0, $source->items()->count());
        $this->assertSame(1, $other->items()->count());
        $this->assertSame(2, $target->items()->count());
        $this->postJson('/api/library/items/bulk', ['action' => 'add', 'item_ulids' => $ulids, 'collection_ulid' => $target->ulid])->assertOk();
        $this->assertSame(2, $target->items()->count());
        $this->assertSame(2, $owner->libraryItems()->count());
    }

    public function test_mixed_ownership_rejects_entire_add_move_remove_and_delete(): void
    {
        $owner = $this->account();
        $outsider = $this->account();
        $own = $this->note($owner);
        $foreign = $this->note($outsider);
        $foreign->members()->attach($owner);
        $source = $owner->libraryCollections()->create(['name' => 'Source']);
        $target = $owner->libraryCollections()->create(['name' => 'Target']);
        $source->items()->attach($own);
        foreach (['add', 'move', 'remove', 'delete'] as $action) {
            $payload = ['action' => $action, 'item_ulids' => [$own->ulid, $foreign->ulid]];
            if (in_array($action, ['add', 'move'], true)) {
                $payload['collection_ulid'] = $target->ulid;
            }
            if (in_array($action, ['move', 'remove'], true)) {
                $payload['source_collection_ulid'] = $source->ulid;
            }
            $this->actingAs($owner)->postJson('/api/library/items/bulk', $payload)->assertNotFound();
            $this->assertDatabaseHas('library_items', ['id' => $own->id]);
            $this->assertDatabaseHas('library_items', ['id' => $foreign->id]);
            $this->assertSame(1, $source->items()->count());
            $this->assertSame(0, $target->items()->count());
        }
    }

    public function test_foreign_collection_and_missing_membership_reject_all_changes(): void
    {
        $owner = $this->account();
        $other = $this->account();
        $first = $this->note($owner);
        $last = $this->note($owner);
        $source = $owner->libraryCollections()->create(['name' => 'Source']);
        $target = $owner->libraryCollections()->create(['name' => 'Target']);
        $foreign = $other->libraryCollections()->create(['name' => 'Shared but foreign']);
        $foreign->members()->attach($owner);
        $source->items()->attach($first);
        $this->actingAs($owner)->postJson('/api/library/items/bulk', [
            'action' => 'add', 'item_ulids' => [$first->ulid], 'collection_ulid' => $foreign->ulid,
        ])->assertNotFound();
        foreach (['move', 'remove'] as $action) {
            $payload = ['action' => $action, 'item_ulids' => [$first->ulid, $last->ulid], 'source_collection_ulid' => $source->ulid];
            if ($action === 'move') {
                $payload['collection_ulid'] = $target->ulid;
            }
            $this->postJson('/api/library/items/bulk', $payload)->assertNotFound();
        }
        $this->assertSame(1, $source->items()->count());
        $this->assertSame(0, $target->items()->count());
        $this->assertSame(0, $foreign->items()->count());
    }

    public function test_disabled_account_can_remove_memberships_and_items_but_cannot_add_or_move(): void
    {
        $owner = $this->account(false);
        $note = $this->note($owner);
        $source = $owner->libraryCollections()->create(['name' => 'Source']);
        $target = $owner->libraryCollections()->create(['name' => 'Target']);
        $source->items()->attach($note);
        foreach (['add', 'move'] as $action) {
            $this->actingAs($owner)->postJson('/api/library/items/bulk', [
                'action' => $action, 'item_ulids' => [$note->ulid], 'collection_ulid' => $target->ulid,
                ...($action === 'move' ? ['source_collection_ulid' => $source->ulid] : []),
            ])->assertForbidden();
        }
        $this->postJson('/api/library/items/bulk', [
            'action' => 'remove', 'item_ulids' => [$note->ulid], 'source_collection_ulid' => $source->ulid,
        ])->assertOk();
        $this->assertDatabaseHas('library_items', ['id' => $note->id]);
        $this->assertSame(0, $source->items()->count());
        $this->postJson('/api/library/items/bulk', ['action' => 'delete', 'item_ulids' => [$note->ulid]])->assertOk();
        $this->assertDatabaseMissing('library_items', ['id' => $note->id]);
    }

    public function test_bulk_organization_never_grants_access_to_saved_items(): void
    {
        $owner = $this->account();
        $recipient = $this->account();
        $saved = $this->note($owner, 'saved');
        $note = $this->note($owner);
        $shared = $owner->libraryCollections()->create(['name' => 'Shared']);
        $shared->members()->attach($recipient);
        $this->actingAs($owner)->postJson('/api/library/items/bulk', [
            'action' => 'add', 'item_ulids' => [$saved->ulid, $note->ulid], 'collection_ulid' => $shared->ulid,
        ])->assertOk();
        $this->actingAs($recipient)->getJson('/api/library/items/'.$saved->ulid)->assertNotFound();
        $this->getJson('/api/library/items?collection_ulid='.$shared->ulid)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.ulid', $note->ulid);
    }

    public function test_bulk_deletion_releases_only_selected_references_and_purges_last_orphan(): void
    {
        Storage::fake('photos');
        $uploader = $this->account();
        $owner = $this->account();
        $other = $this->account();
        $media = Media::factory()->for($uploader)->approved()->create(['disk' => 'photos', 'size_bytes' => 8]);
        Storage::disk('photos')->put($media->object_key, 'original');
        $first = $owner->libraryItems()->create(['media_id' => $media->id, 'source' => 'saved']);
        $last = $other->libraryItems()->create(['media_id' => $media->id, 'source' => 'saved']);
        app(UserAccountService::class)->purge($uploader);
        $this->actingAs($owner)->postJson('/api/library/items/bulk', ['action' => 'delete', 'item_ulids' => [$first->ulid]])->assertOk();
        Storage::disk('photos')->assertExists($media->object_key);
        $this->assertDatabaseHas('media', ['id' => $media->id]);
        $this->getJson('/api/account/storage')->assertOk()->assertJsonPath('data.total_bytes', 0);
        $this->actingAs($other)->getJson('/api/account/storage')->assertOk()->assertJsonPath('data.total_bytes', 8);
        $this->postJson('/api/library/items/bulk', ['action' => 'delete', 'item_ulids' => [$last->ulid]])->assertOk();
        Storage::disk('photos')->assertMissing($media->object_key);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_invalid_batches_fail_validation_without_changes(): void
    {
        $owner = $this->account();
        $note = $this->note($owner);
        $collection = $owner->libraryCollections()->create(['name' => 'Target']);
        foreach ([[], [$note->ulid, $note->ulid], [1], ['unknown'], array_map(fn () => (string) Str::ulid(), range(1, 101))] as $ulids) {
            $this->actingAs($owner)->postJson('/api/library/items/bulk', ['action' => 'delete', 'item_ulids' => $ulids])->assertUnprocessable();
        }
        $this->postJson('/api/library/items/bulk', [
            'action' => 'move', 'item_ulids' => [$note->ulid], 'collection_ulid' => $collection->ulid, 'source_collection_ulid' => $collection->ulid,
        ])->assertUnprocessable();
        $this->postJson('/api/library/items/bulk', ['action' => 'delete', 'item_ulids' => [$note->ulid], 'collection_ulid' => $collection->ulid])->assertUnprocessable();
        $this->postJson('/api/library/items/bulk', ['action' => 'delete', 'item_ulids' => [(string) Str::ulid()]])->assertNotFound();
        $this->assertDatabaseHas('library_items', ['id' => $note->id]);
    }
}
