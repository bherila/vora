<?php

namespace App\Policies;

use App\Enums\StoryStatus;
use App\Models\Story;
use App\Models\User;

class StoryPolicy
{
    /**
     * Admins may do anything with any story (including review).
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    /**
     * Authors (owner + accepted co-authors) may always read their own story,
     * including drafts and unreviewed content. Everyone else may read it only
     * when it is published, has passed admin review, is visible to them, and is
     * owned by an active account.
     */
    public function view(User $user, Story $story): bool
    {
        if ($story->isAuthoredBy($user)) {
            return true;
        }

        $owner = User::withTrashed()->find($story->user_id);
        if ($owner === null || $owner->trashed() || $owner->isDeactivated()) {
            return false;
        }

        return $story->status === StoryStatus::Published
            && $story->isApprovedContent()
            && $story->isVisibleTo($user);
    }

    /**
     * Owner and accepted co-authors may edit the story's content and tags.
     */
    public function update(User $user, Story $story): bool
    {
        return $story->isAuthoredBy($user);
    }

    /**
     * Only the owner may delete the story.
     */
    public function delete(User $user, Story $story): bool
    {
        return $story->user_id === $user->id;
    }

    /**
     * Only the owner may invite or remove co-authors.
     */
    public function manageAuthors(User $user, Story $story): bool
    {
        return $story->user_id === $user->id;
    }
}
