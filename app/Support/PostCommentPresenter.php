<?php

namespace App\Support;

use App\Models\Post;
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

    /**
     * Admin review payload — includes the internal moderation fields and post
     * context needed to review a comment outside the public thread.
     *
     * @return array<string, mixed>
     */
    public static function adminView(PostComment $comment): array
    {
        return self::view($comment) + [
            'post' => self::postRef($comment->post),
            'moderation_status' => $comment->moderation_status->value,
            'moderation_notes' => $comment->moderation_notes,
            'moderated_at' => $comment->moderated_at?->toIso8601String(),
            'moderated_by_user_id' => $comment->moderated_by_user_id,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function postRef(?Post $post): ?array
    {
        if ($post === null) {
            return null;
        }

        return [
            'id' => $post->id,
            'ulid' => $post->ulid,
            'body' => $post->body,
            'author' => $post->user !== null
                ? ['id' => $post->user->id, 'display_name' => $post->user->display_name ?? $post->user->name]
                : null,
        ];
    }
}
