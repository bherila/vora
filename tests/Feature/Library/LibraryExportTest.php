<?php

namespace Tests\Feature\Library;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LibraryExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_includes_owned_notes_saved_snapshots_and_organization_without_foreign_content(): void
    {
        User::factory()->admin()->create(['id' => 1]);
        $owner = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $note = $owner->libraryItems()->create(['source' => 'note', 'title' => 'My note', 'body' => 'My private text']);
        $original = $other->libraryItems()->create(['source' => 'note', 'title' => 'Original', 'body' => 'Old text']);
        $saved = $owner->libraryItems()->create(['source' => 'saved', 'title' => 'Saved snapshot', 'body' => 'Old text', 'source_item_ulid' => $original->ulid]);
        $original->delete();
        $foreign = $other->libraryItems()->create(['source' => 'note', 'title' => 'Foreign title', 'body' => 'Foreign live text']);
        $foreign->members()->attach($owner);
        $collection = $owner->libraryCollections()->create(['name' => 'My collection', 'description' => 'My organization']);
        $collection->items()->attach([$note->id, $saved->id, $foreign->id]);
        $collection->members()->attach($other);
        $otherCollection = $other->libraryCollections()->create(['name' => 'Foreign collection']);
        $otherCollection->items()->attach($note);
        $media = Media::factory()->for($other)->create(['object_key' => 'private-storage-key']);
        $file = $owner->libraryItems()->create(['source' => 'saved', 'title' => 'Saved file', 'media_id' => $media->id]);

        $response = $this->actingAs($owner)->getJson('/api/account/export')->assertOk()
            ->assertJsonCount(3, 'data.library.items')->assertJsonCount(1, 'data.library.collections')
            ->assertJsonPath('data.library.collections.0.name', 'My collection')
            ->assertJsonPath('data.library.collections.0.item_ulids', [$note->ulid, $saved->ulid])
            ->assertJsonPath('data.library.collections.0.shared_with_user_ids', [$other->id]);
        $items = collect($response->json('data.library.items'))->keyBy('ulid');
        $this->assertSame('My private text', $items[$note->ulid]['body']);
        $this->assertSame([$collection->ulid], $items[$note->ulid]['collection_ulids']);
        $this->assertSame('Old text', $items[$saved->ulid]['body']);
        $this->assertSame('media', $items[$file->ulid]['kind']);
        $this->assertArrayNotHasKey('source_item_ulid', $items[$saved->ulid]);
        $this->assertArrayNotHasKey('media_id', $items[$file->ulid]);
        $export = json_encode($response->json('data.library'), JSON_THROW_ON_ERROR);
        foreach (['Foreign live text', 'Foreign title', 'Foreign collection', 'private-storage-key', $other->email] as $hidden) {
            $this->assertStringNotContainsString($hidden, $export);
        }
    }

    #[DataProvider('accountStates')]
    public function test_owned_library_data_remains_exportable_without_feature_access(string $state): void
    {
        User::factory()->admin()->create(['id' => 1]);
        $owner = User::factory()->approved()->create(['library_enabled' => false]);
        if ($state === 'feature disabled') {
            config(['instance.modules.library' => false]);
        } elseif ($state === 'banned and held') {
            $owner->forceFill(['banned_at' => now(), 'legal_hold_at' => now()])->save();
        } elseif ($state === 'deactivated') {
            $owner->forceFill(['deactivated_at' => now()])->save();
        }
        $owner->libraryItems()->create(['source' => 'note', 'title' => 'Retained note', 'body' => 'Still mine']);
        $this->actingAs($owner)->getJson('/api/account/export')->assertOk()
            ->assertJsonPath('data.library.items.0.body', 'Still mine');
        if ($state === 'deactivated') {
            $this->get(route('account.deactivated'))->assertOk()->assertSee(route('account.export'), false);
            $this->getJson('/api/library/items')->assertForbidden();
            $this->getJson('/api/feed')->assertForbidden();
            $this->assertTrue($owner->fresh()->isDeactivated());
        }
    }

    public static function accountStates(): array
    {
        return ['storage disabled' => ['storage disabled'], 'feature disabled' => ['feature disabled'],
            'banned and held' => ['banned and held'], 'deactivated' => ['deactivated']];
    }
}
