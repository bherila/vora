<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    /**
     * Admins may do anything with any post.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    /**
     * The author always sees their own post (any review state). Everyone else
     * may see it only when it has passed review, is viewable to them by its
     * audience, and is owned by an active account. Mirrors MediaPolicy::view.
     */
    public function view(User $user, Post $post): bool
    {
        if ($post->trashed()) {
            return false;
        }

        if ($post->user_id === $user->id) {
            return true;
        }

        $owner = User::withTrashed()->find($post->user_id);
        if ($owner === null || $owner->trashed() || ! $owner->isActive()) {
            return false;
        }

        return $post->isApprovedContent() && $post->isViewableBy($user);
    }

    public function delete(User $user, Post $post): bool
    {
        if ($post->trashed()) {
            return false;
        }

        return $post->user_id === $user->id;
    }
}
