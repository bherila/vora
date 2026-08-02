<?php

namespace App\Support;

use App\Models\Character;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use App\Services\Media\MediaResponseService;

/**
 * Serializes a comment for API responses. Never exposes moderation state.
 */
class PostCommentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function view(
        PostComment $comment,
        ?MediaResponseService $responder = null,
        ?User $viewer = null,
    ): array {
        return self::payload($comment, self::publicAuthor($comment, $responder, $viewer));
    }

    /** @return array<string, mixed> */
    public static function tombstone(PostComment $comment): array
    {
        return [
            'id' => $comment->id,
            'ulid' => $comment->ulid,
            'parent_id' => $comment->parent_id,
            'body' => null,
            'author' => null,
            'deleted' => true,
            'created_at' => $comment->created_at?->toIso8601String(),
            'can_delete' => false,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $author
     * @return array<string, mixed>
     */
    private static function payload(PostComment $comment, ?array $author): array
    {
        return [
            'id' => $comment->id,
            'ulid' => $comment->ulid,
            'parent_id' => $comment->parent_id,
            'body' => $comment->body,
            'author' => $author,
            'created_at' => $comment->created_at?->toIso8601String(),
            'deleted' => false,
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
        return self::payload($comment, UserPresenter::identity($comment->user)) + [
            'post' => self::postRef($comment->post),
            'moderation_status' => $comment->moderation_status->value,
            'moderation_notes' => $comment->moderation_notes,
            'moderated_at' => $comment->moderated_at?->toIso8601String(),
            'moderated_by_user_id' => $comment->moderated_by_user_id,
        ];
    }

    /**
     * A post owner's comment on their Separate-persona post inherits that
     * persona's framing for visitors. Emitting the account identity here would
     * undo the post byline's owner scrub in the first comment thread.
     *
     * @return array<string, mixed>|null
     */
    private static function publicAuthor(
        PostComment $comment,
        ?MediaResponseService $responder,
        ?User $viewer,
    ): ?array {
        $post = $comment->post;
        $character = $post?->character;
        $isOwnerComment = $post instanceof Post
            && $comment->user_id === $post->user_id;
        $managementView = $post instanceof Post
            && $viewer !== null
            && ($viewer->id === $post->user_id || $viewer->isAdmin());

        // Persona links survive a soft delete for restore. If the relation no
        // longer resolves, never reinterpret the owner's comment as account-
        // authored for visitors: that would reveal the human behind the old
        // Separate-persona post. Owner/admin management retains the real author.
        if ($isOwnerComment
            && $post->character_id !== null
            && ! $character instanceof Character
            && ! $managementView) {
            return null;
        }

        if (! $isOwnerComment
            || ! $character instanceof Character
            || $character->is_linked
            || $managementView) {
            return UserPresenter::identity($comment->user, $responder, $viewer);
        }

        $avatar = $character->profilePicture;
        $avatarUrl = null;
        if ($avatar instanceof Media
            && $responder !== null
            && $avatar->isApprovedContent()) {
            $payload = $responder->visitorItem($avatar, resolveHls: false);
            $avatarUrl = $payload['thumbnail_url'] ?? $payload['url'] ?? null;
        }

        return [
            'id' => $character->id,
            'display_name' => $character->display_name,
            'avatar_url' => $avatarUrl,
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
