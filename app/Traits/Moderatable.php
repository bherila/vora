<?php

namespace App\Traits;

use App\Enums\ModerationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reusable admin-review behaviour for user-submitted content. The using model
 * must have `moderation_status` (cast to {@see ModerationStatus}),
 * `moderated_by_user_id`, `moderated_at`, and `moderation_notes` columns.
 *
 * The moderation state is internal: serializers must never expose it to the
 * content's owner. Shared by Media today and future moderated content.
 */
trait Moderatable
{
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by_user_id');
    }

    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where('moderation_status', ModerationStatus::Pending->value);
    }

    public function scopeModerationStatus(Builder $query, ModerationStatus $status): Builder
    {
        return $query->where('moderation_status', $status->value);
    }

    public function isPendingReview(): bool
    {
        return $this->moderation_status === ModerationStatus::Pending;
    }

    public function isApprovedContent(): bool
    {
        return $this->moderation_status === ModerationStatus::Approved;
    }

    public function isRejected(): bool
    {
        return $this->moderation_status === ModerationStatus::Rejected;
    }

    public function approve(User $admin, ?string $notes = null): void
    {
        $this->setModeration(ModerationStatus::Approved, $admin, $notes);
    }

    public function reject(User $admin, ?string $notes = null): void
    {
        $this->setModeration(ModerationStatus::Rejected, $admin, $notes);
    }

    protected function setModeration(ModerationStatus $status, User $admin, ?string $notes): void
    {
        $this->moderation_status = $status;
        $this->moderated_by_user_id = $admin->id;
        $this->moderated_at = now();
        $this->moderation_notes = $notes;
        $this->save();
    }
}
