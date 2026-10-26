<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\Media;
use App\Models\User;
use App\Services\Library\LibraryAccess;
use App\Services\Media\MediaResponseService;

class EventPresenter
{
    public function __construct(private readonly MediaResponseService $media) {}

    /** @return array<string, mixed> */
    public function summary(Event $event, User $viewer): array
    {
        $data = [
            'ulid' => $event->ulid, 'name' => $event->name, 'description' => $event->description,
            'visibility' => $event->visibility->value, 'timezone' => $event->timezone,
            'starts_at' => $event->starts_at?->toIso8601String(), 'ends_at' => $event->ends_at?->toIso8601String(),
            'can_manage' => $viewer->isAdmin(),
        ];
        if ($viewer->isAdmin()) {
            $data['member_ids'] = $event->members->modelKeys();
            $data['member_count'] = $event->members->count();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function media(Media $media, User $viewer, bool $favorited = false, bool $resolveHls = false, ?string $libraryItemUlid = null, ?array $photoVersions = null): array
    {
        $owned = $media->user_id === $viewer->id;
        $data = $owned ? $this->media->item($media, resolveHls: $resolveHls) : $this->media->visitorItem($media, resolveHls: $resolveHls);

        return $data + [
            'event' => $media->event === null ? null : ['ulid' => $media->event->ulid, 'name' => $media->event->name, 'timezone' => $media->event->timezone],
            // When the device captured it, if known; the gallery is ordered by
            // gallery_at, which falls back to upload time.
            'taken_at' => $media->captured_at?->toIso8601String(),
            'gallery_at' => $media->gallery_sort_at?->toIso8601String(),
            'uploader' => ['id' => $media->user_id, 'display_name' => $media->user?->display_name],
            'owned' => $owned, 'favorited' => $favorited,
            'can_save_to_library' => $viewer->canUseLibrary() && app(LibraryAccess::class)->canSaveMedia($viewer, $media),
            'library_item_ulid' => $libraryItemUlid,
            'download_url' => $media->isReady() ? route('events.media.download', ['ulid' => $media->ulid], false) : null,
            'photo_versions' => $photoVersions,
            'photo_details' => $media->photo_width === null && $media->photo_metadata === null ? null : [
                'width' => $media->photo_width, 'height' => $media->photo_height,
                'metadata_known' => $media->photo_metadata !== null,
                'has_gps' => $media->photo_metadata['has_gps'] ?? false,
                'has_capture_time' => $media->photo_metadata['has_capture_time'] ?? false,
                'has_camera' => $media->photo_metadata['has_camera'] ?? false,
            ],
        ];
    }
}
