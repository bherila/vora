<?php

namespace App\Models;

use App\Enums\Audience;
use App\Enums\ModerationStatus;
use App\Traits\HasPrivacyPolicy;
use App\Traits\Moderatable;
use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A short text post — the building block of the follower feed. Reuses the shared
 * privacy (audience/discoverable) and admin-review plumbing, exactly like Media
 * and Story, plus a polymorphic attachment to a Character, Interest, Media, or
 * Story the author owns.
 */
class Post extends Model
{
    use HasFactory;
    use HasPrivacyPolicy;
    use Moderatable;
    use SerializesDatesAsLocal;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'ulid',
        'body',
        'audience',
        'discoverable',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => Audience::class,
            'discoverable' => 'boolean',
            'moderation_status' => ModerationStatus::class,
            'moderated_at' => 'datetime',
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
     * @return HasMany<PostAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(PostAttachment::class);
    }
}
