<?php

namespace App\Policies;

use App\Models\Character;
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
        if ($media->trashed()) {
            return false;
        }

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

        // Soft-deleting a persona deliberately retains media.character_id for
        // restore. The default relation then resolves to null; treating that as
        // account-authored media would expose the human uploader on a URL that
        // visitors learned through the Separate persona. Keep it owner/admin-only
        // until the persona is restored.
        if ($media->character_id !== null && ! $media->character instanceof Character) {
            return false;
        }

        return $media->isApprovedContent() && $media->isViewableBy($user);
    }

    /**
     * Numeric-id lookups are an owner/admin management surface. Public sharing
     * must use the unguessable ULID route so sequential ids cannot enumerate
     * other users' media.
     */
    public function viewById(User $user, Media $media): bool
    {
        if ($media->trashed()) {
            return false;
        }

        return $media->user_id === $user->id;
    }

    public function complete(User $user, Media $media): bool
    {
        if ($media->trashed()) {
            return false;
        }

        return $media->user_id === $user->id;
    }

    public function delete(User $user, Media $media): bool
    {
        if ($media->trashed()) {
            return false;
        }

        return $media->user_id === $user->id;
    }

    public function update(User $user, Media $media): bool
    {
        if ($media->trashed()) {
            return false;
        }

        return $media->user_id === $user->id;
    }
}
