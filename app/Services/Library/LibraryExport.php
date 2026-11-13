<?php

namespace App\Services\Library;

use App\Models\LibraryCollection;
use App\Models\LibraryItem;
use App\Models\User;

/** Export owned Library records without expanding access to shared source content. */
final class LibraryExport
{
    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        $items = $user->libraryItems()->with([
            'collections' => fn ($query) => $query->where('library_collections.user_id', $user->id)->orderBy('library_collections.id'),
            'members:id',
        ])->orderBy('id')->get();
        $collections = $user->libraryCollections()->with([
            'items' => fn ($query) => $query->where('library_items.user_id', $user->id)->orderBy('library_items.id'),
            'members:id',
        ])->orderBy('id')->get();

        return [
            'items' => $items->map(fn (LibraryItem $item): array => [
                'ulid' => $item->ulid,
                'kind' => $item->media_id === null ? 'note' : 'media',
                'source' => $item->source,
                'title' => $item->title,
                'body' => $item->body,
                'collection_ulids' => $item->collections->pluck('ulid')->values()->all(),
                'shared_with_user_ids' => $item->members->pluck('id')->values()->all(),
                'created_at' => $item->created_at?->toIso8601String(),
                'updated_at' => $item->updated_at?->toIso8601String(),
            ])->values()->all(),
            'collections' => $collections->map(fn (LibraryCollection $collection): array => [
                'ulid' => $collection->ulid,
                'name' => $collection->name,
                'description' => $collection->description,
                'related_character_id' => $collection->related_character_id,
                'related_persona_linked' => $collection->related_persona_linked,
                'item_ulids' => $collection->items->pluck('ulid')->values()->all(),
                'shared_with_user_ids' => $collection->members->pluck('id')->values()->all(),
                'created_at' => $collection->created_at?->toIso8601String(),
                'updated_at' => $collection->updated_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
