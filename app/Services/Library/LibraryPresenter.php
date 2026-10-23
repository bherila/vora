<?php

namespace App\Services\Library;

use App\Models\LibraryCollection;
use App\Models\LibraryItem;
use App\Models\Media;
use App\Models\User;
use App\Services\Media\HlsService;
use Illuminate\Support\Collection;

class LibraryPresenter
{
    public function __construct(private readonly LibraryAccess $access, private readonly HlsService $hls) {}

    /** @return array<string, mixed> */
    public function collection(LibraryCollection $collection, User $viewer): array
    {
        $persona = $collection->relatedCharacter;
        $personaAvailable = $persona !== null && $persona->isViewableBy($viewer);

        return $this->collectionData($collection, $viewer, $personaAvailable);
    }

    /** @param Collection<int, LibraryCollection> $collections
     * @return list<array<string, mixed>>
     */
    public function collections(Collection $collections, User $viewer): array
    {
        $personaIds = $this->access->personas($viewer)
            ->whereIn('id', $collections->pluck('related_character_id')->filter()->unique())->pluck('id');

        return $collections->map(fn (LibraryCollection $collection): array => $this->collectionData(
            $collection, $viewer, $collection->relatedCharacter !== null && $personaIds->contains($collection->related_character_id),
        ))->values()->all();
    }

    /** @return array<string, mixed> */
    private function collectionData(LibraryCollection $collection, User $viewer, bool $personaAvailable): array
    {
        $persona = $collection->relatedCharacter;
        $owned = $collection->user_id === $viewer->id;

        return [
            'ulid' => $collection->ulid,
            'name' => $collection->name,
            'description' => $collection->description,
            'owned' => $owned,
            'member_ids' => $owned ? $collection->members->pluck('id')->all() : [],
            'related_persona' => $personaAvailable ? [
                'ulid' => $persona->ulid, 'display_name' => $persona->display_name,
                'href' => route('characters.view', ['ulid' => $persona->ulid], false),
            ] : null,
            'related_persona_unavailable' => ($collection->related_persona_linked || $collection->related_character_id !== null) && ! $personaAvailable,
        ];
    }

    /** @return array<string, mixed> */
    public function item(LibraryItem $item, User $viewer, bool $resolveHls = false): array
    {
        $media = $item->media;
        $available = $media === null || $this->access->canViewMedia($viewer, $media);
        $owned = $item->user_id === $viewer->id;

        return [
            'ulid' => $item->ulid,
            'title' => $item->title,
            'body' => $media === null ? $item->body : null,
            'source' => $item->source,
            'owned' => $owned,
            'available' => $available,
            'member_ids' => $owned ? $item->members->pluck('id')->all() : [],
            'collection_ulids' => $owned ? $item->collections->pluck('ulid')->all() : [],
            'media' => $media instanceof Media && $available ? $this->media($item, $media, $resolveHls) : null,
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function media(LibraryItem $item, Media $media, bool $resolveHls): array
    {
        $video = $media->type->isVideo() ? $this->hls->status($media, $resolveHls) : null;
        if (($video['status'] ?? null) === 'ready') {
            $video['master_url'] = route('library.hls', ['ulid' => $item->ulid, 'path' => 'master.m3u8'], false);
        }

        return [
            'id' => $item->id, 'ulid' => $item->ulid,
            'character_id' => null, 'character' => null, 'interests' => [],
            'type' => $media->type->value, 'purpose' => 'library',
            'title' => $item->title, 'mime_type' => $media->mime_type, 'size_bytes' => $media->size_bytes,
            'audience' => 'specific', 'discoverable' => false, 'upload_status' => $media->upload_status,
            'url' => $media->type->isVideo() || ($media->requiresPhotoPreview() && $media->playbackThumbnailKey() === null) ? null
                : route('library.asset', ['ulid' => $item->ulid, 'variant' => $media->requiresPhotoPreview() ? 'thumbnail' : 'original'], false),
            'thumbnail_url' => $media->playbackThumbnailKey() === null ? null
                : route('library.asset', ['ulid' => $item->ulid, 'variant' => 'thumbnail'], false),
            'video' => $video, 'created_at' => $item->created_at?->toIso8601String(),
        ];
    }
}
