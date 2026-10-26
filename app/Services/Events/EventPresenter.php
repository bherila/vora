<?php

namespace App\Services\Events;

use App\Enums\ModerationStatus;
use App\Models\Event;
use App\Models\Media;
use App\Models\User;
use App\Services\Library\LibraryAccess;
use App\Services\Media\AdminMediaResponseService;
use App\Services\Media\MediaResponseService;

class EventPresenter
{
    public function __construct(
        private readonly MediaResponseService $media,
        private readonly AdminMediaResponseService $admin,
    ) {}

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
        $review = $this->review($media, $viewer, $owned, $resolveHls, $data);

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
            'review' => $review,
            'photo_details' => $media->photo_width === null && $media->photo_metadata === null ? null : [
                'width' => $media->photo_width, 'height' => $media->photo_height,
                'metadata_known' => $media->photo_metadata !== null,
                'has_gps' => $media->photo_metadata['has_gps'] ?? false,
                'has_capture_time' => $media->photo_metadata['has_capture_time'] ?? false,
                'has_camera' => $media->photo_metadata['has_camera'] ?? false,
            ],
        ];
    }

    /**
     * Admins review pending Event uploads in place. They see the same signed
     * preview the admin review page uses, and HEIC/HEIF photos expose the stored
     * original so the reviewer can decode it before approving.
     *
     * @param  array<string, mixed>  $data
     * @return array{pending: true, requires_original_check: bool, original_url: ?string, original_size_bytes: ?int, original_filename: string}|null
     */
    private function review(Media $media, User $viewer, bool $owned, bool $resolveHls, array &$data): ?array
    {
        if (! $viewer->isAdmin() || ! $media->isReady() || $media->moderation_status !== ModerationStatus::Pending) {
            return null;
        }
        $extras = $this->admin->extras($media, $resolveHls);
        if (! $owned) {
            $data['url'] = $extras['url'];
            $data['thumbnail_url'] = $extras['thumbnail_url'];
            $data['video'] = $extras['video'];
        }

        return [
            'pending' => true,
            'requires_original_check' => $media->requiresPhotoPreview(),
            'original_url' => $media->requiresPhotoPreview() ? $extras['download_url'] : null,
            'original_size_bytes' => $media->size_bytes,
            'original_filename' => $media->original_filename,
        ];
    }
}
