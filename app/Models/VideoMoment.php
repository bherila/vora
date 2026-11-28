<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved frame of a video. Two images can exist for it:
 *
 * - the authoritative frame, extracted by the transcoder's frame worker from
 *   approved HLS output at by-id/<content_id>/frames/<time_ms>.jpg, shared by
 *   every moment of the same frame (frame_status);
 * - a provisional capture from the author's browser on the thumbnail disk,
 *   shown only to its author and deleted once the authoritative frame exists.
 *
 * Moments are hard-deleted; reversible states (soft-deleted video, bans,
 * blocks, audience changes) hide them through VideoMomentService::visibleTo.
 */
class VideoMoment extends Model
{
    use HasUlids;

    public const VISIBILITY_SHARED = 'shared';

    public const VISIBILITY_PRIVATE = 'private';

    public const FRAME_PENDING = 'pending';

    public const FRAME_READY = 'ready';

    public const FRAME_FAILED = 'failed';

    public const PROVISIONAL_AWAITING = 'awaiting_upload';

    public const PROVISIONAL_UPLOADED = 'uploaded';

    public const MAX_CAPTION_LENGTH = 280;

    /** @var list<string> */
    protected $fillable = [
        'media_id', 'user_id', 'character_id', 'content_id', 'time_ms', 'caption', 'visibility',
        'frame_status', 'frame_requested_at', 'frame_checked_at', 'provisional_key', 'provisional_status', 'provisional_size_bytes',
    ];

    /** @var list<string> */
    protected $hidden = ['id', 'media_id', 'user_id', 'character_id', 'content_id', 'provisional_key', 'provisional_size_bytes'];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'media_id' => 'integer',
            'user_id' => 'integer',
            'character_id' => 'integer',
            'time_ms' => 'integer',
            'frame_requested_at' => 'datetime',
            'frame_checked_at' => 'datetime',
            'provisional_size_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function frameReady(): bool
    {
        return $this->frame_status === self::FRAME_READY;
    }
}
