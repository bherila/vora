<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;

class MediaPolicy
{
    /**
     * Admins may do anything with any media (including review).
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    /**
     * Viewing honours the media's visibility. The owner (and admins, via
     * before()) may always view their own media regardless of review state;
     * everyone else may only see content that has passed admin review, so a
     * pending or rejected item is never exposed to other users.
     */
    public function view(User $user, Media $media): bool
    {
        if ($media->user_id === $user->id) {
            return true;
        }

        // Media owned by a deleted, deactivated, or disabled account is hidden
        // from other users on every path (direct ULID/HLS links included).
        // Admins bypass this via before(). Mirrors StoryPolicy::view.
        $owner = User::withTrashed()->find($media->user_id);
        if ($owner === null || $owner->trashed() || ! $owner->isActive()) {
            return false;
        }

        return $media->isApprovedContent() && $media->isVisibleTo($user);
    }

    public function complete(User $user, Media $media): bool
    {
        return $media->user_id === $user->id;
    }

    public function delete(User $user, Media $media): bool
    {
        return $media->user_id === $user->id;
    }
}
