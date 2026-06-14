<?php

namespace App\Models;

use App\Enums\MediaType;
use App\Enums\ModerationStatus;
use App\Enums\Visibility;
use App\Traits\HasVisibility;
use App\Traits\Moderatable;
use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A photo or video uploaded by a user. Stored in R2 via a presigned upload;
 * videos additionally get transcoded to HLS by the external s3-hls service.
 *
 * Privacy (visibility) and admin review (moderation) are provided by the shared
 * HasVisibility/Moderatable traits. Moderation state is internal and must never
 * be serialized to the owner.
 */
class Media extends Model
{
    use HasFactory;
    use HasVisibility;
    use Moderatable;
    use SerializesDatesAsLocal;

    protected $table = 'media';

    /**
     * Owner-settable attributes only. Moderation columns are intentionally
     * excluded — they are written solely through the Moderatable trait.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'ulid',
        'type',
        'disk',
        'object_key',
        'original_filename',
        'mime_type',
        'size_bytes',
        'title',
        'upload_status',
        'visibility',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
            'visibility' => Visibility::class,
            'moderation_status' => ModerationStatus::class,
            'moderated_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Interest, $this>
     */
    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(Interest::class, 'media_interests')->withTimestamps();
    }

    public function isReady(): bool
    {
        return $this->upload_status === 'ready';
    }
}
