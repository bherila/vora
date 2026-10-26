<?php

namespace App\Models;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Enums\MediaType;
use App\Enums\ModerationStatus;
use App\Services\Events\EventAccess;
use App\Support\HeifPhoto;
use App\Support\InstanceFeatures;
use App\Traits\HasPrivacyPolicy;
use App\Traits\Moderatable;
use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    use HasPrivacyPolicy {
        scopeViewableBy as private scopeAudienceViewableBy;
        isViewableBy as private isAudienceViewableBy;
        audienceAllows as private genericAudienceAllows;
    }
    use Moderatable;
    use SerializesDatesAsLocal;
    use SoftDeletes;

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
        'multipart_part_size_bytes',
        'multipart_expected_size_bytes',
        'multipart_max_part_number',
        'multipart_initiated_at',
        // Dedup plumbing is internal; only the admin presenter exposes it.
        'file_hash',
        'duplicate_of_media_id',
        'pdq_hash',
        'pdq_checked_at',
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
        'event_id',
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
        'file_hash',
        'duplicate_of_media_id',
        'size_bytes',
        'title',
        'upload_status',
        'multipart_upload_id',
        'multipart_part_size_bytes',
        'multipart_expected_size_bytes',
        'multipart_max_part_number',
        'multipart_initiated_at',
        'audience',
        'discoverable',
        'announce_on_approval',
        'canonical_post_id',
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
            'announce_on_approval' => 'boolean',
            'moderation_status' => ModerationStatus::class,
            'moderated_at' => 'datetime',
            'hls_checked_at' => 'datetime',
            'pdq_checked_at' => 'datetime',
            'size_bytes' => 'integer',
            'multipart_part_size_bytes' => 'integer',
            'multipart_expected_size_bytes' => 'integer',
            'multipart_max_part_number' => 'integer',
            'multipart_initiated_at' => 'datetime',
            'character_id' => 'integer',
            'event_id' => 'integer',
            'photo_width' => 'integer',
            'photo_height' => 'integer',
            'photo_pixel_area' => 'integer',
            'photo_metadata_score' => 'integer',
            'photo_metadata' => 'array',
            'captured_at' => 'datetime',
            'gallery_sort_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Every row gets a gallery position: its capture time when known,
        // otherwise when the upload began.
        static::creating(function (Media $media): void {
            $media->gallery_sort_at ??= $media->captured_at ?? $media->created_at ?? $media->freshTimestamp();
        });
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

    /** @return BelongsTo<Post, $this> */
    public function canonicalPost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'canonical_post_id');
    }

    /**
     * The earlier item this one was flagged as a likely duplicate of, if any.
     *
     * @return BelongsTo<Media, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'duplicate_of_media_id');
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

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** Event publication access is independent of the generic media audience. */
    public function audienceAllows(User $viewer): bool
    {
        if ($this->isGalleryMedia() && ! $viewer->isAdmin() && ! InstanceFeatures::enabled('profiles')) {
            return false;
        }

        if ($this->purpose === MediaPurpose::Event && $viewer->id !== $this->user_id
            && ! app(EventAccess::class)->canView($viewer, $this->event)) {
            return false;
        }

        return $this->genericAudienceAllows($viewer);
    }

    public function isViewableBy(?User $viewer): bool
    {
        if ($this->isGalleryMedia() && ! $viewer?->isAdmin() && ! InstanceFeatures::enabled('profiles')) {
            return false;
        }

        if ($this->purpose === MediaPurpose::Event && $viewer !== null && ! $viewer->isAdmin()
            && ($viewer->trashed() || ! $viewer->isActive() || ! $viewer->isApproved())) {
            return false;
        }

        return $this->isAudienceViewableBy($viewer);
    }

    /**
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    public function scopeViewableBy(Builder $query, ?User $viewer): Builder
    {
        $this->scopeAudienceViewableBy($query, $viewer);
        if ($viewer?->isAdmin()) {
            return $query;
        }

        if (! InstanceFeatures::enabled('profiles')) {
            $query->where('media.purpose', '!=', MediaPurpose::Gallery->value);
        }

        return $query->where(function (Builder $visible) use ($viewer): void {
            $visible->where('media.purpose', '!=', MediaPurpose::Event->value);
            if (! $viewer instanceof User || $viewer->trashed() || ! $viewer->isActive() || ! $viewer->isApproved()) {
                return;
            }
            $visible->orWhere(function (Builder $eventMedia) use ($viewer): void {
                $eventMedia->where('media.purpose', MediaPurpose::Event->value)->where(function (Builder $access) use ($viewer): void {
                    $access->where('media.user_id', $viewer->id)->orWhere(function (Builder $publication) use ($viewer): void {
                        $publication->where('upload_status', 'ready')->where('moderation_status', 'approved')
                            ->whereHas('user', fn (Builder $owner) => $owner->active()->whereNotNull('approved_at'))
                            ->whereHas('event', fn (Builder $events) => app(EventAccess::class)->visibleTo($events, $viewer));
                    });
                });
            });
        });
    }

    public function isGalleryMedia(): bool
    {
        return $this->purpose === MediaPurpose::Gallery;
    }

    public function supportsMultipartUpload(): bool
    {
        return in_array($this->purpose, [MediaPurpose::Gallery, MediaPurpose::Library, MediaPurpose::Event], true);
    }

    /** @return HasMany<LibraryItem, $this> */
    public function libraryItems(): HasMany
    {
        return $this->hasMany(LibraryItem::class);
    }

    public function isProfilePicture(): bool
    {
        return $this->purpose === MediaPurpose::ProfilePicture;
    }

    public function isReady(): bool
    {
        return $this->upload_status === 'ready';
    }

    public function requiresPhotoPreview(): bool
    {
        return $this->type === MediaType::Photo && HeifPhoto::isMimeType($this->mime_type);
    }

    /** Privacy-safe download names are independent of the uploader's filename. */
    public function downloadExtension(): string
    {
        return match (strtolower($this->mime_type)) {
            'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif',
            'image/heic' => 'heic', 'image/heif' => 'heif',
            'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/x-matroska' => 'mkv',
            default => 'jpg',
        };
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
