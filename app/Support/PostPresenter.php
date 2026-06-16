<?php

namespace App\Support;

use App\Enums\StoryStatus;
use App\Models\Character;
use App\Models\Interest;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostAttachment;
use App\Models\Story;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Serializes a Post for API responses. Pure (no I/O). Never exposes moderation
 * state.
 *
 * Enforces the attachment privacy intersection: a privacy-controlled attachment
 * (Media/Story) is only included when the viewer could view it on its own — so a
 * post can never widen the audience of the thing it attaches. Characters and
 * Interests are public profile/tag references and are always shown.
 */
class PostPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function view(Post $post, ?User $viewer): array
    {
        return [
            'id' => $post->id,
            'ulid' => $post->ulid,
            'body' => $post->body,
            'audience' => $post->audience->value,
            'discoverable' => $post->discoverable,
            'author' => self::author($post->user),
            'attachments' => self::attachments($post, $viewer),
            'reaction_count' => self::reactionCount($post),
            'viewer_reacted' => self::viewerReacted($post, $viewer),
            'created_at' => $post->created_at?->toIso8601String(),
        ];
    }

    /**
     * Prefer the counts loaded by {@see Post::scopeWithReactionState()} (set on
     * listings to avoid an N+1); fall back to a query for single-item contexts.
     */
    private static function reactionCount(Post $post): int
    {
        return (int) ($post->reactions_count ?? $post->reactions()->count());
    }

    private static function viewerReacted(Post $post, ?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if ($post->viewer_reaction_count !== null) {
            return (int) $post->viewer_reaction_count > 0;
        }

        return $post->reactions()->where('user_id', $viewer->id)->exists();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function author(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return ['id' => $user->id, 'display_name' => $user->display_name ?? $user->name];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function attachments(Post $post, ?User $viewer): array
    {
        if (! $post->relationLoaded('attachments')) {
            return [];
        }

        return $post->attachments
            ->map(fn (PostAttachment $attachment): ?array => self::attachment($attachment->attachable, $viewer))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function attachment(?Model $attachable, ?User $viewer): ?array
    {
        // Null when the target was deleted out from under the attachment, or when
        // the intersection rule hides a privacy-controlled item from this viewer.
        if ($attachable === null || ! self::canSee($attachable, $viewer)) {
            return null;
        }

        return match (true) {
            $attachable instanceof Character => ['type' => 'character', 'id' => $attachable->id, 'label' => $attachable->display_name],
            $attachable instanceof Interest => ['type' => 'interest', 'id' => $attachable->id, 'label' => $attachable->name],
            $attachable instanceof Media => ['type' => 'media', 'id' => $attachable->id, 'ulid' => $attachable->ulid, 'media_type' => $attachable->type->value, 'label' => $attachable->title],
            $attachable instanceof Story => ['type' => 'story', 'id' => $attachable->id, 'ulid' => $attachable->ulid, 'label' => $attachable->title],
            default => null,
        };
    }

    /**
     * The intersection gate for privacy-controlled attachments (Media/Story):
     * the viewer must own it / be an admin, or it must be approved, viewable to
     * them by its own audience, and (for stories) published — i.e. the same rule
     * as opening it directly.
     *
     * The owner-active check the policies also apply is intentionally omitted: an
     * attachment is always the post author's own content, and the post is only
     * ever presented when that author is active (the feed filters inactive
     * owners; the single-post policy requires an active owner). Checking it here
     * via Gate would re-fetch every attachment's owner — an N+1 across a feed
     * page — for a condition already guaranteed by the surrounding post.
     */
    private static function canSee(Model $attachable, ?User $viewer): bool
    {
        if ($attachable instanceof Media) {
            return self::ownerOrAdmin($attachable->user_id, $viewer)
                || ($attachable->isApprovedContent() && $attachable->isViewableBy($viewer));
        }

        if ($attachable instanceof Story) {
            return self::ownerOrAdmin($attachable->user_id, $viewer)
                || ($attachable->status === StoryStatus::Published
                    && $attachable->isApprovedContent()
                    && $attachable->isViewableBy($viewer));
        }

        return true;
    }

    private static function ownerOrAdmin(int $ownerId, ?User $viewer): bool
    {
        return $viewer !== null && ($viewer->isAdmin() || $viewer->id === $ownerId);
    }
}
