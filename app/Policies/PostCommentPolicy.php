<?php

namespace App\Policies;

use App\Models\PostComment;
use App\Models\User;

class PostCommentPolicy
{
    /**
     * Authors delete their own contribution. Post-owner moderation is a distinct
     * recorded action exposed through removeFromPost().
     */
    public function delete(User $user, PostComment $comment): bool
    {
        return $comment->user_id === $user->id;
    }

    public function removeFromPost(User $user, PostComment $comment): bool
    {
        return $comment->post->user_id === $user->id && $comment->user_id !== $user->id;
    }
}
