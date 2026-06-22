<?php

namespace App\Services\Favorites;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\Favorite;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use App\Services\Privacy\ProfileGate;
use App\Support\FollowGraph;
use App\Support\UserPresenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Favorites are bookmarks that never widen access. This service owns the two
 * privacy-sensitive concerns: resolving a client-supplied {type,id} to a model,
 * and deciding whether a given viewer may see a favorited item — so a favorites
 * list only ever shows entries the viewer could already reach on their own.
 */
class FavoriteService
{
    /**
     * Client type keys → model classes. The stored favoritable_type uses each
     * model's getMorphClass() (which honours the morph map), so reads and writes
     * stay consistent regardless of these keys.
     *
     * @var array<string, class-string<Model>>
     */
    private const TYPES = [
        'media' => Media::class,
        'story' => Story::class,
        'post' => Post::class,
        'user' => User::class,
        'character' => Character::class,
    ];

    public function __construct(
        private readonly MediaResponseService $responder,
        private readonly ProfileGate $profileGate,
    ) {}

    /**
     * Resolve a client {type,id} to its model, or null if the type is unknown or
     * the row does not exist.
     */
    public function resolve(string $type, int $id): ?Model
    {
        $class = self::TYPES[$type] ?? null;
        if ($class === null) {
            return null;
        }

        return $class::query()->find($id);
    }

    /**
     * May this viewer see this favoritable item? Delegates to the item's own
     * privacy policy (media/story/post) or audience rules (user/character), so a
     * favorite never bypasses privacy.
     */
    public function canViewerSee(User $viewer, Model $item): bool
    {
        return match (true) {
            $item instanceof Media,
            $item instanceof Post,
            $item instanceof Story => Gate::forUser($viewer)->allows('view', $item),
            $item instanceof User => $this->canSeeUser($viewer, $item),
            $item instanceof Character => $this->canSeeCharacter($viewer, $item),
            default => false,
        };
    }

    /**
     * The owner's favorites that the viewer may see, newest first, as uniform
     * card payloads. Entries the viewer cannot see are dropped entirely.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleFor(User $owner, User $viewer): array
    {
        return $owner->favorites()
            ->with('favoritable')
            ->latest()
            ->get()
            ->map(fn ($favorite): ?Model => $favorite->favoritable)
            ->filter()
            ->filter(fn (Model $item): bool => $this->canViewerSee($viewer, $item))
            ->map(fn (Model $item): array => $this->present($item, $viewer))
            ->values()
            ->all();
    }

    /**
     * How many users have favorited this item. An aggregate count only — it
     * names no one — so it is safe to show to anyone who can already see the
     * item.
     */
    public function countFor(Model $item): int
    {
        return Favorite::query()
            ->where('favoritable_type', $item->getMorphClass())
            ->where('favoritable_id', $item->getKey())
            ->count();
    }

    /**
     * Of the given ids for one favoritable type, the subset the viewer has
     * favorited — one query, so a listing can be annotated with a `favorited`
     * flag without an N+1.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function favoritedIdsFor(?User $viewer, string $type, array $ids): array
    {
        $class = self::TYPES[$type] ?? null;
        if (! $viewer instanceof User || $class === null || $ids === []) {
            return [];
        }

        return Favorite::query()
            ->where('user_id', $viewer->id)
            ->where('favoritable_type', (new $class)->getMorphClass())
            ->whereIn('favoritable_id', $ids)
            ->pluck('favoritable_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Add a `favorited` flag to each row of a `{data, meta}` listing payload,
     * matched by row id. Rows must carry an integer `id`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function annotateListing(array $payload, ?User $viewer, string $type): array
    {
        $rows = $payload['data'] ?? [];
        $ids = array_map(fn (array $row): int => (int) $row['id'], $rows);
        $favorited = array_flip($this->favoritedIdsFor($viewer, $type, $ids));

        $payload['data'] = array_map(
            fn (array $row): array => $row + ['favorited' => isset($favorited[(int) $row['id']])],
            $rows,
        );

        return $payload;
    }

    /**
     * A uniform card for any favoritable item. The optional viewer lets avatar
     * thumbnails honour moderation: an unreviewed profile picture is shown only
     * to its owner, matching {@see UserPresenter::pictureUrl}.
     *
     * @return array<string, mixed>
     */
    public function present(Model $item, ?User $viewer = null): array
    {
        return match (true) {
            $item instanceof Media => [
                'type' => 'media',
                'id' => $item->id,
                'label' => $item->title ?: $item->original_filename,
                'subtitle' => ucfirst($item->type->value ?? (string) $item->type),
                'href' => "/m/{$item->ulid}",
                'thumbnail_url' => $this->mediaThumb($item),
            ],
            $item instanceof Story => [
                'type' => 'story',
                'id' => $item->id,
                'label' => $item->title,
                'subtitle' => 'Story',
                'href' => "/s/{$item->ulid}",
                'thumbnail_url' => null,
            ],
            $item instanceof Post => [
                'type' => 'post',
                'id' => $item->id,
                'label' => Str::limit((string) $item->body, 80) ?: 'Post',
                'subtitle' => 'Post',
                'href' => "/p/{$item->ulid}",
                'thumbnail_url' => null,
            ],
            $item instanceof User => [
                'type' => 'user',
                'id' => $item->id,
                'label' => $item->display_name ?: $item->name,
                'subtitle' => 'Profile',
                'href' => "/users/{$item->id}",
                'thumbnail_url' => UserPresenter::avatarUrl($item, $this->responder, $viewer),
            ],
            $item instanceof Character => [
                'type' => 'character',
                'id' => $item->id,
                'label' => $item->display_name,
                'subtitle' => 'Character',
                'href' => "/users/{$item->user_id}",
                'thumbnail_url' => UserPresenter::pictureUrl($item->profilePicture, $this->responder, $viewer),
            ],
            default => [
                'type' => 'unknown',
                'id' => $item->getKey(),
                'label' => '',
                'subtitle' => '',
                'href' => '#',
                'thumbnail_url' => null,
            ],
        };
    }

    private function mediaThumb(?Media $media): ?string
    {
        if (! $media instanceof Media) {
            return null;
        }

        $payload = $this->responder->item($media, resolveHls: false);

        return $payload['thumbnail_url'] ?? $payload['url'] ?? null;
    }

    private function canSeeUser(User $viewer, User $target): bool
    {
        return $target->approved_at !== null
            && $target->isActive()
            && $this->profileGate->canView($viewer, $target);
    }

    private function canSeeCharacter(User $viewer, Character $character): bool
    {
        $owner = $character->user;
        if ($owner === null || $owner->approved_at === null || ! $owner->isActive()) {
            return false;
        }

        if ($viewer->id === $owner->id || $viewer->isAdmin()) {
            return true;
        }

        // Characters carry their own audience but no allowlist table, so
        // "specific people" resolves to owner-only for everyone else.
        return match ($character->audience) {
            Audience::Everyone => true,
            Audience::Followers => FollowGraph::follows($viewer->id, $owner->id),
            Audience::Mutuals => FollowGraph::mutual($viewer->id, $owner->id),
            Audience::SpecificPeople => false,
        };
    }
}
