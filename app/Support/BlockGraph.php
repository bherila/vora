<?php

namespace App\Support;

use App\Models\Block;
use App\Models\Character;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\StoryAuthor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * The single source of truth for block visibility.
 *
 * Blocks are deliberately asymmetric. Denial always applies to the blocked
 * account as a whole, preventing persona-based evasion. Hiding is observable by
 * the blocker, so it crosses only publicly-linked identities and never reveals
 * that a Separate persona belongs to an account.
 */
final class BlockGraph
{
    public static function canViewIdentity(
        User $viewer,
        int $ownerId,
        ?int $characterId = null,
    ): bool {
        if ($viewer->isAdmin() || $viewer->id === $ownerId) {
            return true;
        }

        if (self::isDenied($viewer->id, $ownerId)) {
            return false;
        }

        return ! self::isHidden($viewer->id, $ownerId, $characterId);
    }

    public static function canViewStory(User $viewer, Story $story): bool
    {
        if ($viewer->isAdmin() || $story->isAuthoredBy($viewer)) {
            return true;
        }

        return StoryAuthor::query()
            ->where('story_id', $story->id)
            ->where('status', StoryAuthor::STATUS_ACCEPTED)
            ->get(['user_id', 'character_id'])
            ->every(fn (StoryAuthor $author): bool => self::canViewIdentity(
                $viewer,
                $author->user_id,
                $author->character_id,
            ));
    }

    public static function canViewModelIdentity(User $viewer, Model $model): bool
    {
        return match (true) {
            $model instanceof Story => self::canViewStory($viewer, $model),
            $model instanceof Character => self::canViewIdentity($viewer, $model->user_id, $model->id),
            $model instanceof Media,
            $model instanceof Post => self::canViewIdentity($viewer, $model->user_id, $model->character_id),
            $model instanceof User => self::canViewIdentity($viewer, $model->id),
            default => false,
        };
    }

    /**
     * The blocked party loses access to every identity owned by the blocker.
     */
    public static function isDenied(int $viewerId, int $ownerId): bool
    {
        return Block::query()
            ->where('blocker_id', $ownerId)
            ->where('blocked_user_id', $viewerId)
            ->exists();
    }

    /**
     * The blocker hides only the selected identity's public linkage cluster.
     */
    public static function isHidden(
        int $viewerId,
        int $ownerId,
        ?int $characterId = null,
    ): bool {
        $identityIsLinked = $characterId !== null && Character::query()
            ->whereKey($characterId)
            ->where('user_id', $ownerId)
            ->where('is_linked', true)
            ->exists();

        return Block::query()
            ->where('blocker_id', $viewerId)
            ->where('blocked_user_id', $ownerId)
            ->where(function (EloquentBuilder $query) use ($characterId, $identityIsLinked): void {
                if ($characterId === null || $identityIsLinked) {
                    $query->whereNull('blocked_character_id')
                        ->orWhereHas(
                            'blockedCharacter',
                            fn (EloquentBuilder $character): EloquentBuilder => $character->where('is_linked', true),
                        );

                    if ($characterId !== null) {
                        $query->orWhere('blocked_character_id', $characterId);
                    }

                    return;
                }

                $query->where('blocked_character_id', $characterId);
            })
            ->exists();
    }

    /**
     * Constrain an outer identity query to rows visible through the block graph.
     *
     * @param  QueryBuilder  $query  inner builder passed by whereNotExists
     */
    public static function constrainDenied(
        QueryBuilder $query,
        string $ownerColumn,
        int $viewerId,
    ): void {
        $query->from('blocks as denial_blocks')
            ->whereColumn('denial_blocks.blocker_id', $ownerColumn)
            ->where('denial_blocks.blocked_user_id', $viewerId);
    }

    /**
     * Constrain the observable hide half for a human or persona outer identity.
     *
     * @param  QueryBuilder  $query  inner builder passed by whereNotExists
     */
    public static function constrainHidden(
        QueryBuilder $query,
        string $ownerColumn,
        int $viewerId,
        ?string $characterColumn = null,
    ): void {
        $query->from('blocks as hide_blocks')
            ->where('hide_blocks.blocker_id', $viewerId)
            ->whereColumn('hide_blocks.blocked_user_id', $ownerColumn);

        if ($characterColumn === null) {
            $query->where(function (QueryBuilder $identity): void {
                $identity->whereNull('hide_blocks.blocked_character_id')
                    ->orWhereExists(function (QueryBuilder $blockedCharacter): void {
                        self::constrainLinkedCharacter(
                            $blockedCharacter,
                            'hide_blocks.blocked_character_id',
                            'hide_blocks.blocked_user_id',
                            'blocked_linked_characters',
                        );
                    });
            });

            return;
        }

        $query->where(function (QueryBuilder $identity) use ($ownerColumn, $characterColumn): void {
            // An account or Linked persona belongs to the public linkage
            // cluster, so an account/Linked block hides the whole cluster.
            $identity->where(function (QueryBuilder $publicCluster) use ($ownerColumn, $characterColumn): void {
                $publicCluster->where(function (QueryBuilder $outerIdentity) use ($ownerColumn, $characterColumn): void {
                    $outerIdentity->whereNull($characterColumn)
                        ->orWhereExists(function (QueryBuilder $outerCharacter) use ($ownerColumn, $characterColumn): void {
                            self::constrainLinkedCharacter(
                                $outerCharacter,
                                $characterColumn,
                                $ownerColumn,
                                'outer_linked_characters',
                            );
                        });
                })->where(function (QueryBuilder $blockedIdentity): void {
                    $blockedIdentity->whereNull('hide_blocks.blocked_character_id')
                        ->orWhereExists(function (QueryBuilder $blockedCharacter): void {
                            self::constrainLinkedCharacter(
                                $blockedCharacter,
                                'hide_blocks.blocked_character_id',
                                'hide_blocks.blocked_user_id',
                                'blocked_linked_characters',
                            );
                        });
                });
            })
                // Separate personas are hidden only when explicitly selected.
                ->orWhereColumn('hide_blocks.blocked_character_id', $characterColumn);
        });
    }

    /**
     * Apply both halves to an Eloquent listing before pagination.
     *
     * @param  EloquentBuilder<*>  $query
     * @return EloquentBuilder<*>
     */
    public static function visibleTo(
        EloquentBuilder $query,
        User $viewer,
        string $ownerColumn,
        ?string $characterColumn = null,
    ): EloquentBuilder {
        if ($viewer->isAdmin()) {
            return $query;
        }

        return $query
            ->whereNotExists(fn (QueryBuilder $blocks) => self::constrainDenied(
                $blocks,
                $ownerColumn,
                $viewer->id,
            ))
            ->whereNotExists(fn (QueryBuilder $blocks) => self::constrainHidden(
                $blocks,
                $ownerColumn,
                $viewer->id,
                $characterColumn,
            ));
    }

    /**
     * Stories carry their surfaced identity on the accepted owner authorship
     * row rather than stories.character_id.
     *
     * @param  EloquentBuilder<Story>  $query
     * @return EloquentBuilder<Story>
     */
    public static function storiesVisibleTo(
        EloquentBuilder $query,
        User $viewer,
        string $table = 'stories',
    ): EloquentBuilder {
        if ($viewer->isAdmin()) {
            return $query;
        }

        $query->whereNotExists(function (QueryBuilder $blocks) use ($table, $viewer): void {
            $blocks->from('blocks as story_denial_blocks')
                ->where('story_denial_blocks.blocked_user_id', $viewer->id)
                ->whereExists(function (QueryBuilder $author) use ($table): void {
                    $author->selectRaw('1')
                        ->from('story_authors as denied_story_authors')
                        ->whereColumn('denied_story_authors.story_id', "{$table}.id")
                        ->where('denied_story_authors.status', StoryAuthor::STATUS_ACCEPTED)
                        ->whereColumn('denied_story_authors.user_id', 'story_denial_blocks.blocker_id');
                });
        });

        return $query->whereNotExists(function (QueryBuilder $blocks) use ($table, $viewer): void {
            $blocks->from('blocks as story_hide_blocks')
                ->where('story_hide_blocks.blocker_id', $viewer->id)
                ->whereExists(function (QueryBuilder $author) use ($table): void {
                    $author->selectRaw('1')
                        ->from('story_authors as blocked_story_authors')
                        ->whereColumn('blocked_story_authors.story_id', "{$table}.id")
                        ->where('blocked_story_authors.status', StoryAuthor::STATUS_ACCEPTED)
                        ->whereColumn('blocked_story_authors.user_id', 'story_hide_blocks.blocked_user_id')
                        ->where(function (QueryBuilder $identity): void {
                            $identity->where(function (QueryBuilder $publicCluster): void {
                                $publicCluster->where(function (QueryBuilder $outerIdentity): void {
                                    $outerIdentity->whereNull('blocked_story_authors.character_id')
                                        ->orWhereExists(function (QueryBuilder $outerCharacter): void {
                                            self::constrainLinkedCharacter(
                                                $outerCharacter,
                                                'blocked_story_authors.character_id',
                                                'blocked_story_authors.user_id',
                                                'story_linked_characters',
                                            );
                                        });
                                })->where(function (QueryBuilder $blockedIdentity): void {
                                    $blockedIdentity->whereNull('story_hide_blocks.blocked_character_id')
                                        ->orWhereExists(function (QueryBuilder $blockedCharacter): void {
                                            self::constrainLinkedCharacter(
                                                $blockedCharacter,
                                                'story_hide_blocks.blocked_character_id',
                                                'story_hide_blocks.blocked_user_id',
                                                'story_blocked_linked_characters',
                                            );
                                        });
                                });
                            })->orWhereColumn(
                                'story_hide_blocks.blocked_character_id',
                                'blocked_story_authors.character_id',
                            );
                        });
                });
        });
    }

    /**
     * Notifications persist a server-only surfaced actor identity in JSON.
     * Reusing the ordinary identity constraint keeps pagination/count/update
     * operations in-query and applies the same asymmetric hide rule.
     *
     * @param  EloquentBuilder<*>  $query
     * @return EloquentBuilder<*>
     */
    public static function notificationsVisibleTo(EloquentBuilder $query, User $viewer): EloquentBuilder
    {
        return self::visibleTo(
            $query,
            $viewer,
            'notifications.data->_actor_user_id',
            'notifications.data->_actor_character_id',
        );
    }

    /**
     * A post owner's comment inherits the post's surfaced persona; every other
     * comment is account-authored. Preserve that framing in the hide half while
     * denial still vetoes the commenter's whole account.
     *
     * @param  EloquentBuilder<*>  $query
     * @return EloquentBuilder<*>
     */
    public static function commentsVisibleTo(EloquentBuilder $query, User $viewer): EloquentBuilder
    {
        if ($viewer->isAdmin()) {
            return $query;
        }

        $query->whereNotExists(fn (QueryBuilder $blocks) => self::constrainDenied(
            $blocks,
            'post_comments.user_id',
            $viewer->id,
        ));

        return $query->whereNotExists(function (QueryBuilder $blocks) use ($viewer): void {
            $blocks->from('blocks as comment_hide_blocks')
                ->where('comment_hide_blocks.blocker_id', $viewer->id)
                ->whereColumn('comment_hide_blocks.blocked_user_id', 'post_comments.user_id')
                ->whereExists(function (QueryBuilder $post): void {
                    $post->selectRaw('1')
                        ->from('posts as comment_posts')
                        ->whereColumn('comment_posts.id', 'post_comments.post_id')
                        ->where(function (QueryBuilder $identity): void {
                            $identity->where(function (QueryBuilder $publicCluster): void {
                                $publicCluster->where(function (QueryBuilder $outerIdentity): void {
                                    $outerIdentity
                                        ->whereColumn('comment_posts.user_id', '!=', 'post_comments.user_id')
                                        ->orWhereNull('comment_posts.character_id')
                                        ->orWhereExists(function (QueryBuilder $outerCharacter): void {
                                            self::constrainLinkedCharacter(
                                                $outerCharacter,
                                                'comment_posts.character_id',
                                                'comment_posts.user_id',
                                                'comment_linked_characters',
                                            );
                                        });
                                })->where(function (QueryBuilder $blockedIdentity): void {
                                    $blockedIdentity->whereNull('comment_hide_blocks.blocked_character_id')
                                        ->orWhereExists(function (QueryBuilder $blockedCharacter): void {
                                            self::constrainLinkedCharacter(
                                                $blockedCharacter,
                                                'comment_hide_blocks.blocked_character_id',
                                                'comment_hide_blocks.blocked_user_id',
                                                'comment_blocked_linked_characters',
                                            );
                                        });
                                });
                            })->orWhere(function (QueryBuilder $exactPersona): void {
                                $exactPersona
                                    ->whereColumn('comment_posts.user_id', 'post_comments.user_id')
                                    ->whereColumn(
                                        'comment_hide_blocks.blocked_character_id',
                                        'comment_posts.character_id',
                                    );
                            });
                        });
                });
        });
    }

    /**
     * Batch account-identity vetoes for ProfileGate::canViewMany().
     *
     * @param  Collection<int, User>  $targets
     * @return Collection<int, true>
     */
    public static function hiddenAccountIds(User $viewer, Collection $targets): Collection
    {
        if ($viewer->isAdmin() || $targets->isEmpty()) {
            return collect();
        }

        $targetIds = $targets->pluck('id');
        $denied = Block::query()
            ->where('blocked_user_id', $viewer->id)
            ->whereIn('blocker_id', $targetIds)
            ->pluck('blocker_id');
        $hidden = Block::query()
            ->where('blocker_id', $viewer->id)
            ->whereIn('blocked_user_id', $targetIds)
            ->where(function (EloquentBuilder $query): void {
                $query->whereNull('blocked_character_id')
                    ->orWhereHas(
                        'blockedCharacter',
                        fn (EloquentBuilder $character): EloquentBuilder => $character->where('is_linked', true),
                    );
            })
            ->pluck('blocked_user_id');

        return $denied->merge($hidden)->mapWithKeys(fn ($id): array => [(int) $id => true]);
    }

    private static function constrainLinkedCharacter(
        QueryBuilder $query,
        string $characterColumn,
        string $ownerColumn,
        string $alias,
    ): void {
        $query->selectRaw('1')
            ->from("characters as {$alias}")
            ->whereColumn("{$alias}.id", $characterColumn)
            ->whereColumn("{$alias}.user_id", $ownerColumn)
            ->whereNull("{$alias}.deleted_at")
            ->where("{$alias}.is_linked", true);
    }
}
