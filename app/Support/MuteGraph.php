<?php

namespace App\Support;

use App\Models\Mute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Viewer-side, exact-identity mute rules.
 *
 * Unlike blocking, a mute never cascades through an account. A human mute
 * matches only character-less content from that user; a persona mute matches
 * only that character id, regardless of whether the persona is Linked or
 * Separate. Keeping the correlated forms here prevents listing queries from
 * drifting away from the boolean notification/profile rule.
 */
class MuteGraph
{
    public static function isMutedIdentity(
        int $viewerId,
        int $ownerId,
        ?int $characterId,
    ): bool {
        return Mute::query()
            ->where('user_id', $viewerId)
            ->when(
                $characterId === null,
                fn (Builder $query): Builder => $query->where('muted_user_id', $ownerId)
                    ->whereNull('muted_character_id'),
                fn (Builder $query): Builder => $query->where('muted_character_id', $characterId)
                    ->whereNull('muted_user_id'),
            )
            ->exists();
    }

    /**
     * Exclude exact muted identities from a query with owner and optional
     * character columns. This belongs before pagination, especially on the
     * cursor-paginated feed, so a page cannot become short after filtering.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function excludeMutedIdentities(
        Builder $query,
        int $viewerId,
        string $ownerColumn,
        ?string $characterColumn = null,
    ): Builder {
        return $query->whereNotExists(function (QueryBuilder $mutes) use (
            $viewerId,
            $ownerColumn,
            $characterColumn,
        ): void {
            $mutes->selectRaw('1')
                ->from('mutes')
                ->where('mutes.user_id', $viewerId)
                ->where(function (QueryBuilder $target) use ($ownerColumn, $characterColumn): void {
                    $target->where(function (QueryBuilder $human) use ($ownerColumn, $characterColumn): void {
                        $human->whereColumn('mutes.muted_user_id', $ownerColumn)
                            ->whereNull('mutes.muted_character_id');

                        if ($characterColumn !== null) {
                            $human->whereNull($characterColumn);
                        }
                    });

                    if ($characterColumn !== null) {
                        $target->orWhere(function (QueryBuilder $persona) use ($characterColumn): void {
                            $persona->whereColumn('mutes.muted_character_id', $characterColumn)
                                ->whereNull('mutes.muted_user_id');
                        });
                    }
                });
        });
    }

    /**
     * Exclude stories authored as an exact muted identity. Story ownership is
     * account-level, but the public byline identity lives on story_authors.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function excludeMutedStoryAuthors(
        Builder $query,
        int $viewerId,
        string $storyColumn = 'stories.id',
    ): Builder {
        return $query->whereNotExists(function (QueryBuilder $mutes) use ($viewerId, $storyColumn): void {
            $mutes->selectRaw('1')
                ->from('mutes')
                ->join('story_authors', function ($join) use ($storyColumn): void {
                    $join->on('story_authors.story_id', $storyColumn)
                        ->where('story_authors.status', 'accepted');
                })
                ->where('mutes.user_id', $viewerId)
                ->where(function (QueryBuilder $target): void {
                    $target->where(function (QueryBuilder $human): void {
                        $human->whereColumn('mutes.muted_user_id', 'story_authors.user_id')
                            ->whereNull('mutes.muted_character_id')
                            ->whereNull('story_authors.character_id');
                    })->orWhere(function (QueryBuilder $persona): void {
                        $persona->whereColumn('mutes.muted_character_id', 'story_authors.character_id')
                            ->whereNull('mutes.muted_user_id');
                    });
                });
        });
    }

    /**
     * Hide previously-created notifications when their exact actor is now
     * muted. New notifications are also stopped before delivery by the social
     * notification classes, which prevents both database and web-push delivery.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function excludeMutedNotifications(Builder $query, int $viewerId): Builder
    {
        $mutes = Mute::query()->where('user_id', $viewerId)->get([
            'muted_user_id',
            'muted_character_id',
        ]);
        $userIds = $mutes->pluck('muted_user_id')->filter()
            ->map(fn ($id): int => (int) $id)->values()->all();
        $characterIds = $mutes->pluck('muted_character_id')->filter()
            ->map(fn ($id): int => (int) $id)->values()->all();

        if ($characterIds !== []) {
            $query->where(function (Builder $notifications) use ($characterIds): void {
                $notifications->whereNull('data->actor_character_id')
                    ->orWhereNotIn('data->actor_character_id', $characterIds);
            });
        }

        if ($userIds !== []) {
            $query->where(function (Builder $notifications) use ($userIds): void {
                // A persona notification may carry its public owner's actor_id
                // when Linked. actor_character_id wins so a human mute cannot
                // cascade to that persona.
                $notifications->whereNotNull('data->actor_character_id')
                    ->orWhereNull('data->actor_id')
                    ->orWhereNotIn('data->actor_id', $userIds);
            });
        }

        return $query;
    }

    /**
     * Exclude exact user/persona targets from a polymorphic profile-card query,
     * such as the recently visited side rail.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function excludeMutedProfileTargets(
        Builder $query,
        int $viewerId,
        string $typeColumn,
        string $idColumn,
        string $userType,
        string $characterType,
    ): Builder {
        return $query->whereNotExists(function (QueryBuilder $mutes) use (
            $viewerId,
            $typeColumn,
            $idColumn,
            $userType,
            $characterType,
        ): void {
            $mutes->selectRaw('1')
                ->from('mutes')
                ->where('mutes.user_id', $viewerId)
                ->where(function (QueryBuilder $target) use (
                    $typeColumn,
                    $idColumn,
                    $userType,
                    $characterType,
                ): void {
                    $target->where(function (QueryBuilder $human) use ($typeColumn, $idColumn, $userType): void {
                        $human->where($typeColumn, $userType)
                            ->whereColumn('mutes.muted_user_id', $idColumn);
                    })->orWhere(function (QueryBuilder $persona) use ($typeColumn, $idColumn, $characterType): void {
                        $persona->where($typeColumn, $characterType)
                            ->whereColumn('mutes.muted_character_id', $idColumn);
                    });
                });
        });
    }
}
