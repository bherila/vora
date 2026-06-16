<?php

namespace App\Policies;

use App\Models\PostComment;
use App\Models\User;

class PostCommentPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    /**
     * A comment may be removed by its author or by the owner of the post it is on
     * (admins via before()).
     */
    public function delete(User $user, PostComment $comment): bool
    {
        return $comment->user_id === $user->id
            || $comment->post->user_id === $user->id;
    }
}
