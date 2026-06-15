<?php

namespace App\Services\Story;

use App\Models\Character;
use App\Models\Story;
use App\Models\StoryAuthor;
use App\Models\StoryNode;
use App\Models\User;
use App\Support\StoryPresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Write-side operations for stories: tag syncing and CYOA graph reconciliation.
 * Read-side shaping lives in {@see StoryPresenter}.
 */
class StoryService
{
    /**
     * Eager-load every relation the presenters read, so callers never N+1.
     */
    public function loadForPresentation(Story $story): Story
    {
        return $story->load([
            'user',
            'interests',
            'involvements.involvable',
            'authors.user',
            'nodes',
            'choices',
        ]);
    }

    /**
     * Replace the story's interest tags with the given interest ids.
     *
     * @param  list<int>  $interestIds
     */
    public function syncInterests(Story $story, array $interestIds): void
    {
        $story->interests()->sync(array_values(array_unique(array_map('intval', $interestIds))));
    }

    /**
     * Replace the story's "involves" tags. Each entry is
     * ['type' => 'user'|'character', 'id' => int]. Entries that are not an
     * author of the story (or a character owned by one) are dropped, so the tag
     * set can only ever reference the people actually behind the story.
     *
     * @param  list<array{type: string, id: int}>  $involvements
     */
    public function syncInvolvements(Story $story, array $involvements): void
    {
        $allowed = $this->allowedInvolvables($story);

        $rows = collect($involvements)
            ->map(fn (array $entry): array => ['type' => (string) $entry['type'], 'id' => (int) $entry['id']])
            ->filter(fn (array $entry): bool => in_array($entry['type'].':'.$entry['id'], $allowed, true))
            ->unique(fn (array $entry): string => $entry['type'].':'.$entry['id']);

        DB::transaction(function () use ($story, $rows): void {
            $story->involvements()->delete();
            foreach ($rows as $entry) {
                $story->involvements()->create([
                    'involvable_type' => $entry['type'],
                    'involvable_id' => $entry['id'],
                ]);
            }
        });
    }

    /**
     * Identifiers ("type:id") this story is allowed to involve: every author
     * user and every character those authors own.
     *
     * @return list<string>
     */
    public function allowedInvolvables(Story $story): array
    {
        $authorIds = $story->authors()
            ->where('status', StoryAuthor::STATUS_ACCEPTED)
            ->pluck('user_id')
            ->push($story->user_id)
            ->unique()
            ->values();

        $users = $authorIds->map(fn (int $id): string => 'user:'.$id);

        $characterIds = Character::query()
            ->whereIn('user_id', $authorIds)
            ->pluck('id')
            ->map(fn (int $id): string => 'character:'.$id);

        return $users->merge($characterIds)->values()->all();
    }

    /**
     * The named "involves" options an author may pick from: every accepted
     * author and every character those authors own.
     *
     * @return list<array{type: string, id: int, name: string}>
     */
    public function involvableOptions(Story $story): array
    {
        $authorUsers = $story->authors()
            ->where('status', StoryAuthor::STATUS_ACCEPTED)
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter();

        $options = $authorUsers
            ->map(fn (User $u): array => ['type' => 'user', 'id' => $u->id, 'name' => $u->display_name ?: $u->name])
            ->values();

        $characters = Character::query()
            ->whereIn('user_id', $authorUsers->pluck('id'))
            ->orderBy('display_name')
            ->get()
            ->map(fn (Character $c): array => ['type' => 'character', 'id' => $c->id, 'name' => $c->display_name]);

        return $options->merge($characters)->values()->all();
    }

    /**
     * Reconcile the full CYOA graph from the editor in one transaction. Nodes are
     * matched by their client `key`; missing nodes are deleted (cascading their
     * choices), and all choices are rebuilt from the payload.
     *
     * @param  list<array{key: string, title?: ?string, body?: ?string, is_start?: bool, position_x?: float, position_y?: float}>  $nodes
     * @param  list<array{from: string, to?: ?string, label: string, position?: int}>  $choices
     */
    public function saveGraph(Story $story, array $nodes, array $choices): void
    {
        DB::transaction(function () use ($story, $nodes, $choices): void {
            $keys = [];
            $startAssigned = false;

            foreach ($nodes as $node) {
                $key = (string) ($node['key'] ?? '');
                if ($key === '') {
                    $key = (string) Str::ulid();
                }
                $isStart = (bool) ($node['is_start'] ?? false) && ! $startAssigned;
                if ($isStart) {
                    $startAssigned = true;
                }

                $story->nodes()->updateOrCreate(
                    ['key' => $key],
                    [
                        'title' => $node['title'] ?? null,
                        'body' => $node['body'] ?? null,
                        'is_start' => $isStart,
                        'position_x' => (float) ($node['position_x'] ?? 0),
                        'position_y' => (float) ($node['position_y'] ?? 0),
                    ],
                );
                $keys[] = $key;
            }

            // Delete nodes no longer present (cascades their outgoing choices and
            // nulls incoming choice targets via the FK rules).
            $story->nodes()->whereNotIn('key', $keys === [] ? [''] : $keys)->delete();

            // If nothing was flagged as the start, promote the first remaining node
            // so a CYOA story always has an entry point.
            if (! $startAssigned) {
                $first = $story->nodes()->orderBy('id')->first();
                if ($first instanceof StoryNode) {
                    $first->update(['is_start' => true]);
                }
            }

            // Rebuild choices wholesale, mapping client keys to node ids.
            $nodeIdByKey = $story->nodes()->pluck('id', 'key');
            $story->choices()->delete();

            foreach ($choices as $choice) {
                $fromKey = (string) ($choice['from'] ?? '');
                if (! $nodeIdByKey->has($fromKey)) {
                    continue;
                }
                $toKey = $choice['to'] ?? null;
                // A null target is a deliberate ending; a non-null target that no
                // longer exists is a dangling edge (its node was deleted) and the
                // whole choice is dropped rather than silently turned into an ending.
                if ($toKey !== null && ! $nodeIdByKey->has($toKey)) {
                    continue;
                }
                $toId = $toKey !== null ? $nodeIdByKey->get($toKey) : null;

                $story->choices()->create([
                    'from_node_id' => $nodeIdByKey->get($fromKey),
                    'to_node_id' => $toId,
                    'label' => (string) ($choice['label'] ?? 'Continue'),
                    'position' => (int) ($choice['position'] ?? 0),
                ]);
            }
        });
    }

    /**
     * Ensure the creating user holds the owner authorship row.
     */
    public function ensureOwnerAuthor(Story $story, User $owner): void
    {
        $story->authors()->updateOrCreate(
            ['user_id' => $owner->id],
            [
                'role' => StoryAuthor::ROLE_OWNER,
                'status' => StoryAuthor::STATUS_ACCEPTED,
                'responded_at' => now(),
            ],
        );
    }
}
