<?php

namespace App\Support;

use App\Models\PostComment;

/**
 * Serializes a comment for API responses. Never exposes moderation state.
 */
class PostCommentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function view(PostComment $comment): array
    {
        return [
            'id' => $comment->id,
            'parent_id' => $comment->parent_id,
            'body' => $comment->body,
            'author' => $comment->user !== null
                ? ['id' => $comment->user->id, 'display_name' => $comment->user->display_name ?? $comment->user->name]
                : null,
            'created_at' => $comment->created_at?->toIso8601String(),
        ];
    }
}
