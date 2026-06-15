<?php

namespace App\Support;

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
 * Moderation state is internal (see {@see Moderatable}); only
 * {@see self::adminView()} exposes it.
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
        return [
            'id' => $story->id,
            'ulid' => $story->ulid,
            'title' => $story->title,
            'type' => $story->type->value,
            'status' => $story->status->value,
            'visibility' => $story->visibility->value,
            'owner' => self::userRef($story->user),
            'interests' => self::interests($story),
            'involves' => self::involvements($story),
            'authors' => self::authors($story),
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
        return [
            'id' => $story->id,
            'ulid' => $story->ulid,
            'title' => $story->title,
            'type' => $story->type->value,
            'status' => $story->status->value,
            'body' => $story->body,
            'owner' => self::userRef($story->user),
            'authors' => self::authors($story, acceptedOnly: true),
            'interests' => self::interests($story),
            'involves' => self::involvements($story),
            'nodes' => $story->isCyoa() ? self::nodes($story) : [],
            'choices' => $story->isCyoa() ? self::choices($story) : [],
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
     * @return list<array<string, mixed>>
     */
    private static function involvements(Story $story): array
    {
        return $story->involvements
            ->map(function (StoryInvolvement $involvement): ?array {
                $involvable = $involvement->involvable;
                if ($involvable === null) {
                    return null;
                }

                $type = $involvable instanceof Character ? 'character' : 'user';
                $name = $involvable instanceof Character
                    ? $involvable->display_name
                    : ($involvable->display_name ?: $involvable->name);

                return ['type' => $type, 'id' => $involvable->id, 'name' => $name];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function authors(Story $story, bool $acceptedOnly = false): array
    {
        return $story->authors
            ->filter(fn (StoryAuthor $author): bool => ! $acceptedOnly || $author->isAccepted())
            ->map(fn (StoryAuthor $author): array => [
                'id' => $author->id,
                'user_id' => $author->user_id,
                'display_name' => $author->user?->display_name ?: $author->user?->name,
                'role' => $author->role,
                'status' => $author->status,
                'is_owner' => $author->isOwner(),
            ])
            ->values()
            ->all();
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
