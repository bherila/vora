<?php

namespace App\Support;

use App\Enums\ModerationStatus;
use App\Models\Character;
use App\Models\Story;
use App\Models\StoryAuthor;
use App\Models\StoryChoice;
use App\Models\StoryInvolvement;
use App\Models\StoryNode;
use App\Models\User;
use App\Traits\Moderatable;

/**
 * Turns Story models into API payloads. Centralised so the author editor, the
 * public reader, the library listing, and the admin queue stay in lock-step.
 *
 * Moderation state is internal (see {@see Moderatable}); author-facing review
 * status is intentionally limited to a small public subset, and only
 * {@see self::adminView()} exposes the full moderation model.
 */
class StoryPresenter
{
    /**
     * Compact row for a listing (library / future discovery surfaces).
     *
     * @return array<string, mixed>
     */
    public static function summary(Story $story): array
    {
        // Author/admin-facing payload: it must keep *all* authorship rows
        // (including pending invites, which the editor's CoAuthorPanel renders as
        // "invited" and excludes from the invite dropdown) and all involvement
        // tags. The public reader surface — the only cross-user one — does its own
        // active-account filtering in readerView(); a future discovery feed (#27)
        // must do the same rather than relying on this shape.
        return [
            'id' => $story->id,
            'ulid' => $story->ulid,
            'title' => $story->title,
            'type' => $story->type->value,
            'status' => $story->status->value,
            'audience' => $story->audience->value,
            'discoverable' => $story->discoverable,
            'owner' => self::userRef($story->user),
            'interests' => self::interests($story),
            'involves' => self::involvements($story),
            'authors' => self::authors($story),
            'review' => self::review($story),
            'node_count' => $story->isCyoa() ? ($story->nodes_count ?? $story->nodes()->count()) : null,
            'published_at' => $story->published_at?->format('Y-m-d H:i:s'),
            'created_at' => $story->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $story->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Full payload for an author editing the story. Excludes moderation fields.
     *
     * @return array<string, mixed>
     */
    public static function editorView(Story $story): array
    {
        return array_merge(self::summary($story), [
            'body' => $story->body,
            'nodes' => self::nodes($story),
            'choices' => self::choices($story),
            'can_manage_authors' => false, // set by the controller per-viewer
        ]);
    }

    /**
     * Reader payload for someone viewing a published story. Excludes drafts'
     * internal data and all moderation fields.
     *
     * @return array<string, mixed>
     */
    public static function readerView(Story $story): array
    {
        // Inactive (deactivated/disabled/deleted) co-authors and their tags must
        // not leak to other readers, matching the account-lifecycle guarantee.
        // The owner's own active status is enforced by StoryPolicy::view.
        $activeAuthors = $story->authors
            ->filter(fn (StoryAuthor $a): bool => $a->isAccepted() && self::isActiveUser($a->user));
        $activeUserIds = $activeAuthors->pluck('user_id')->map(fn ($id): int => (int) $id)->all();

        return [
            'id' => $story->id,
            'ulid' => $story->ulid,
            'title' => $story->title,
            'type' => $story->type->value,
            'status' => $story->status->value,
            'body' => $story->body,
            'owner' => self::userRef($story->user),
            'authors' => $activeAuthors
                ->map(fn (StoryAuthor $author): array => self::authorRef($author))
                ->values()
                ->all(),
            'interests' => self::interests($story),
            'involves' => self::involvements($story, $activeUserIds),
            'nodes' => $story->isCyoa() ? self::nodes($story) : [],
            'choices' => $story->isCyoa() ? self::choices($story) : [],
            'published_at' => $story->published_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Compact discovery row for public Explore. Mirrors reader-safe fields and
     * does not include body, graph, moderation internals, or involvement tags.
     *
     * @return array<string, mixed>
     */
    public static function discoverableView(Story $story): array
    {
        return [
            'id' => $story->id,
            'ulid' => $story->ulid,
            'title' => $story->title,
            'type' => $story->type->value,
            'owner' => self::userRef($story->user),
            'authors' => $story->authors
                ->filter(fn (StoryAuthor $author): bool => $author->isAccepted() && self::isActiveUser($author->user))
                ->map(fn (StoryAuthor $author): array => self::authorRef($author))
                ->values()
                ->all(),
            'interests' => self::interests($story),
            'node_count' => $story->isCyoa() ? ($story->nodes_count ?? $story->nodes()->count()) : null,
            'published_at' => $story->published_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Admin review payload — includes the internal moderation fields.
     *
     * @return array<string, mixed>
     */
    public static function adminView(Story $story): array
    {
        return array_merge(self::summary($story), [
            'body' => $story->body,
            'moderation_status' => $story->moderation_status->value,
            'moderation_notes' => $story->moderation_notes,
            'moderated_at' => $story->moderated_at?->toIso8601String(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function interests(Story $story): array
    {
        return $story->interests
            ->map(fn ($interest): array => ['id' => $interest->id, 'name' => $interest->name])
            ->values()
            ->all();
    }

    /**
     * Minimal author-facing review information. This deliberately avoids
     * exposing moderator ids/timestamps or raw internal column names.
     *
     * @return array{status: string, label: string, note: string|null}
     */
    private static function review(Story $story): array
    {
        $status = $story->moderation_status ?? ModerationStatus::Pending;

        return [
            'status' => $status->value,
            'label' => $status->label(),
            'note' => $story->isRejected() ? $story->moderation_notes : null,
        ];
    }

    /**
     * @param  list<int>|null  $allowedUserIds  when set, only involvements tied to
     *                                          these author user ids (directly or
     *                                          via a character they own) are kept
     * @return list<array<string, mixed>>
     */
    private static function involvements(Story $story, ?array $allowedUserIds = null): array
    {
        return $story->involvements
            ->map(function (StoryInvolvement $involvement) use ($allowedUserIds): ?array {
                $involvable = $involvement->involvable;
                if ($involvable === null) {
                    return null;
                }

                if ($involvable instanceof Character) {
                    if ($allowedUserIds !== null && ! in_array((int) $involvable->user_id, $allowedUserIds, true)) {
                        return null;
                    }

                    return ['type' => 'character', 'id' => $involvable->id, 'name' => $involvable->display_name];
                }

                if ($allowedUserIds !== null && ! in_array((int) $involvable->id, $allowedUserIds, true)) {
                    return null;
                }

                return ['type' => 'user', 'id' => $involvable->id, 'name' => $involvable->display_name ?: $involvable->name];
            })
            ->filter()
            ->values()
            ->all();
    }

    private static function isActiveUser(?User $user): bool
    {
        // A soft-deleted user resolves to null via the default relation scope;
        // User::isActive() covers the deactivated + disabled states.
        return $user !== null && $user->isActive();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function authors(Story $story): array
    {
        return $story->authors
            ->map(fn (StoryAuthor $author): array => self::authorRef($author))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function authorRef(StoryAuthor $author): array
    {
        return [
            'id' => $author->id,
            'user_id' => $author->user_id,
            'character_id' => $author->character_id,
            'display_name' => $author->character?->display_name
                ?: ($author->user?->display_name ?: $author->user?->name),
            'role' => $author->role,
            'status' => $author->status,
            'is_owner' => $author->isOwner(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function nodes(Story $story): array
    {
        return $story->nodes
            ->map(fn (StoryNode $node): array => [
                'id' => $node->id,
                'key' => $node->key,
                'title' => $node->title,
                'body' => $node->body,
                'is_start' => $node->is_start,
                'position_x' => $node->position_x,
                'position_y' => $node->position_y,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function choices(Story $story): array
    {
        return $story->choices
            ->sortBy([['from_node_id', 'asc'], ['position', 'asc']])
            ->map(fn (StoryChoice $choice): array => [
                'id' => $choice->id,
                'from_node_id' => $choice->from_node_id,
                'to_node_id' => $choice->to_node_id,
                'label' => $choice->label,
                'position' => $choice->position,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function userRef(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'display_name' => $user->display_name ?: $user->name,
        ];
    }
}
