<?php

namespace App\Models;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Enums\MediaType;
use App\Enums\ModerationStatus;
use App\Traits\HasPrivacyPolicy;
use App\Traits\Moderatable;
use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A photo or video uploaded by a user. Stored in R2 via a presigned upload;
 * videos additionally get transcoded to HLS by the external s3-hls service.
 *
 * Privacy (audience) and admin review (moderation) are provided by the shared
 * HasPrivacyPolicy/Moderatable traits. Moderation state is internal and must
 * never be serialized to the owner.
 */
class Media extends Model
{
    use HasFactory;
    use HasPrivacyPolicy;
    use Moderatable;
    use SerializesDatesAsLocal;

    protected $table = 'media';

    /**
     * HLS resolution fields are internal plumbing, not part of API responses.
     *
     * @var list<string>
     */
    protected $hidden = [
        'hls_content_id',
        'hls_checked_at',
        'reviewed_object_key',
        'reviewed_thumbnail_key',
        'thumbnail_key',
        'multipart_upload_id',
    ];

    /**
     * Owner-settable attributes only. Moderation columns are intentionally
     * excluded — they are written solely through the Moderatable trait.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'character_id',
        'ulid',
        'type',
        'purpose',
        'disk',
        'object_key',
        'reviewed_object_key',
        'thumbnail_key',
        'reviewed_thumbnail_key',
        'original_filename',
        'mime_type',
        'perceptual_hash',
        'size_bytes',
        'title',
        'upload_status',
        'multipart_upload_id',
        'multipart_part_size_bytes',
        'multipart_initiated_at',
        'audience',
        'discoverable',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
            'purpose' => MediaPurpose::class,
            'audience' => Audience::class,
            'discoverable' => 'boolean',
            'moderation_status' => ModerationStatus::class,
            'moderated_at' => 'datetime',
            'hls_checked_at' => 'datetime',
            'size_bytes' => 'integer',
            'multipart_part_size_bytes' => 'integer',
            'multipart_initiated_at' => 'datetime',
            'character_id' => 'integer',
        ];
    }

    /**
     * Whether the transcoder has produced HLS output for this video (its
     * content id has been resolved and cached).
     */
    public function isHlsReady(): bool
    {
        return $this->type === MediaType::Video && $this->hls_content_id !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Optional character this media is associated with. When present, the media
     * inherits the character's audience and allowlist.
     *
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /**
     * @return BelongsToMany<Interest, $this>
     */
    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(Interest::class, 'media_interests')->withTimestamps();
    }

    public function isGalleryMedia(): bool
    {
        return $this->purpose === MediaPurpose::Gallery;
    }

    public function isProfilePicture(): bool
    {
        return $this->purpose === MediaPurpose::ProfilePicture;
    }

    public function isReady(): bool
    {
        return $this->upload_status === 'ready';
    }

    public function playbackObjectKey(): string
    {
        if ($this->isApprovedContent() && $this->reviewed_object_key !== null) {
            return $this->reviewed_object_key;
        }

        return $this->object_key;
    }

    public function playbackThumbnailKey(): ?string
    {
        if ($this->isApprovedContent() && $this->reviewed_thumbnail_key !== null) {
            return $this->reviewed_thumbnail_key;
        }

        return $this->thumbnail_key;
    }

    /**
     * Restrict a listing to a single media type. A null type is a no-op so
     * callers can pass an optional filter straight through.
     *
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    public function scopeOfType(Builder $query, ?MediaType $type): Builder
    {
        if ($type === null) {
            return $query;
        }

        return $query->where('type', $type->value);
    }

    /**
     * Restrict a listing to rows tagged with at least one of the given interest
     * ids. An empty list is a no-op. Uses whereHas so it composes with the other
     * listing scopes without duplicating rows.
     *
     * @param  Builder<Media>  $query
     * @param  list<int>  $interestIds
     * @return Builder<Media>
     */
    public function scopeWithAnyInterest(Builder $query, array $interestIds): Builder
    {
        if ($interestIds === []) {
            return $query;
        }

        return $query->whereHas('interests', function (Builder $q) use ($interestIds): void {
            $q->whereIn('interests.id', $interestIds);
        });
    }
}
