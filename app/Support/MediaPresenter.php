<?php

namespace App\Support;

use App\Enums\ModerationStatus;
use App\Models\Character;
use App\Models\Interest;
use App\Models\Media;

/**
 * Serializes Media for API responses. The owner/public shape never includes the
 * moderation DECISION or notes — admin review stays silent; only adminView()
 * exposes those. It does carry a derived `under_review` flag so an owner knows
 * their freshly-uploaded item isn't visible to others yet (it is only ever true
 * on items the viewer owns, since others can't see un-approved media at all).
 *
 * Pure: signed URLs and HLS status are computed by the caller and passed in via
 * $extras (keys: "url" for a signed view URL, "download_url" for an admin-only
 * source download URL, "video" for HLS status), so this class performs no I/O
 * and is trivial to test.
 *
 * @phpstan-type Extras array{url?: ?string, download_url?: ?string, thumbnail_url?: ?string, video?: ?array<string, mixed>}
 */
class MediaPresenter
{
    /**
     * Owner/public representation — no moderation fields.
     *
     * @param  Extras  $extras
     * @return array<string, mixed>
     */
    public static function ownerView(Media $media, array $extras = []): array
    {
        return self::base($media, $extras);
    }

    /**
     * Admin representation — includes the internal review state and uploader.
     *
     * @param  Extras  $extras
     * @return array<string, mixed>
     */
    public static function adminView(Media $media, array $extras = []): array
    {
        return self::base($media, $extras) + [
            'moderation_status' => $media->moderation_status->value,
            'moderation_notes' => $media->moderation_notes,
            'moderated_at' => $media->moderated_at?->toIso8601String(),
            'moderated_by_user_id' => $media->moderated_by_user_id,
            'download_url' => $extras['download_url'] ?? null,
            // Dedup signals are admin-only: the exact-bytes hash and a pointer to
            // an earlier item this one was flagged as a likely duplicate of.
            'file_hash' => $media->file_hash,
            'duplicate_of_media_id' => $media->duplicate_of_media_id,
            'user' => [
                'id' => $media->user_id,
                'name' => $media->user?->name,
                'email' => $media->user?->email,
            ],
        ];
    }

    /**
     * @param  Extras  $extras
     * @return array<string, mixed>
     */
    private static function base(Media $media, array $extras): array
    {
        return [
            'id' => $media->id,
            'ulid' => $media->ulid,
            'character_id' => $media->character_id,
            'type' => $media->type->value,
            'purpose' => $media->purpose->value,
            'title' => $media->title,
            'original_filename' => $media->original_filename,
            'mime_type' => $media->mime_type,
            'size_bytes' => $media->size_bytes,
            'audience' => $media->audience->value,
            'discoverable' => $media->discoverable,
            'upload_status' => $media->upload_status,
            // Derived "not yet visible to others" flag (no decision/notes leaked).
            'under_review' => $media->moderation_status !== ModerationStatus::Approved,
            'url' => $extras['url'] ?? null,
            'thumbnail_url' => $extras['thumbnail_url'] ?? null,
            'video' => $extras['video'] ?? null,
            'interests' => $media->relationLoaded('interests')
                ? $media->interests->map(fn (Interest $i): array => ['id' => $i->id, 'name' => $i->name])->all()
                : [],
            'character' => $media->relationLoaded('character') && $media->character instanceof Character
                ? ['id' => $media->character->id, 'display_name' => $media->character->display_name]
                : null,
            'created_at' => $media->created_at?->toIso8601String(),
        ];
    }
}
