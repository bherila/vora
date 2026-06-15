<?php

namespace App\Support;

use App\Models\Interest;
use App\Models\Media;

/**
 * Serializes Media for API responses. The owner/public shape NEVER includes
 * moderation state — admin review is silent. Only adminView() exposes it.
 *
 * Pure: signed URLs and HLS status are computed by the caller and passed in via
 * $extras (keys: "url" for a signed view URL, "video" for HLS status), so this
 * class performs no I/O and is trivial to test.
 *
 * @phpstan-type Extras array{url?: ?string, thumbnail_url?: ?string, video?: ?array<string, mixed>}
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
            'type' => $media->type->value,
            'purpose' => $media->purpose->value,
            'title' => $media->title,
            'original_filename' => $media->original_filename,
            'mime_type' => $media->mime_type,
            'size_bytes' => $media->size_bytes,
            'visibility' => $media->visibility->value,
            'upload_status' => $media->upload_status,
            'url' => $extras['url'] ?? null,
            'thumbnail_url' => $extras['thumbnail_url'] ?? null,
            'video' => $extras['video'] ?? null,
            'interests' => $media->relationLoaded('interests')
                ? $media->interests->map(fn (Interest $i): array => ['id' => $i->id, 'name' => $i->name])->all()
                : [],
            'created_at' => $media->created_at?->toIso8601String(),
        ];
    }
}
