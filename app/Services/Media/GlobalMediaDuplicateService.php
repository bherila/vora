<?php

namespace App\Services\Media;

use App\Enums\MediaType;
use App\Models\Media;
use App\Support\PerceptualHash;
use Illuminate\Support\Collection;

/**
 * Admin-only cross-account PDQ matching.
 *
 * The service deliberately has no owner/visitor presenter integration. Its
 * output is consumed only by controllers behind the `admin-only` gate, keeping
 * the platform-wide existence signal out of uploader-facing responses.
 */
class GlobalMediaDuplicateService
{
    /**
     * @var Collection<int, Media>|null
     */
    private ?Collection $photos = null;

    /**
     * @var list<array{left_id: int, right_id: int, distance: int}>|null
     */
    private ?array $pairs = null;

    private bool $scanTruncated = false;

    /**
     * Direct cross-account matches for media already loaded into an admin page.
     *
     * @param  Collection<int, Media>  $media
     * @return array<int, array{other_account_count: int, match_count: int, matches: list<array<string, mixed>>}>
     */
    public function summariesFor(Collection $media): array
    {
        $targetIds = $media->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $matches = array_fill_keys($targetIds, []);
        $photos = $this->photos();

        foreach ($this->pairs() as $pair) {
            if (array_key_exists($pair['left_id'], $matches)) {
                $candidate = $photos->get($pair['right_id']);
                if ($candidate instanceof Media) {
                    $matches[$pair['left_id']][] = $this->matchPayload($candidate, $pair['distance']);
                }
            }
            if (array_key_exists($pair['right_id'], $matches)) {
                $candidate = $photos->get($pair['left_id']);
                if ($candidate instanceof Media) {
                    $matches[$pair['right_id']][] = $this->matchPayload($candidate, $pair['distance']);
                }
            }
        }

        return collect($matches)->map(function (array $directMatches): array {
            usort($directMatches, fn (array $a, array $b): int => [$a['distance'], $a['media_id']] <=> [$b['distance'], $b['media_id']]);

            return [
                'other_account_count' => collect($directMatches)->pluck('account_id')->unique()->count(),
                'match_count' => count($directMatches),
                'matches' => array_values($directMatches),
            ];
        })->all();
    }

    /**
     * Connected groups formed by direct cross-account PDQ matches.
     *
     * Connected components keep the cluster stable when A≈B and B≈C even if
     * A and C sit just outside the strict global threshold. Every edge still
     * represents a direct cross-account match; same-owner-only pairs never
     * create a cluster.
     *
     * @return list<array{
     *     id: string,
     *     media_count: int,
     *     account_count: int,
     *     newest_at: ?string,
     *     media: list<Media>
     * }>
     */
    public function clusters(string $sort = 'size_desc'): array
    {
        /** @var array<int, int> $parents */
        $parents = [];

        foreach ($this->pairs() as $pair) {
            $parents[$pair['left_id']] ??= $pair['left_id'];
            $parents[$pair['right_id']] ??= $pair['right_id'];
            $this->union($parents, $pair['left_id'], $pair['right_id']);
        }

        /** @var array<int, list<int>> $components */
        $components = [];
        foreach (array_keys($parents) as $id) {
            $components[$this->root($parents, $id)][] = $id;
        }

        $photos = $this->photos();
        $clusters = collect($components)
            ->map(function (array $ids) use ($photos): array {
                $items = collect($ids)
                    ->map(fn (int $id): ?Media => $photos->get($id))
                    ->filter(fn (?Media $media): bool => $media instanceof Media)
                    ->sortBy('id')
                    ->values();
                $newest = $items->max('created_at');

                return [
                    'id' => 'cluster-'.$items->min('id'),
                    'media_count' => $items->count(),
                    'account_count' => $items->pluck('user_id')->unique()->count(),
                    'newest_at' => $newest?->toIso8601String(),
                    'media' => $items->all(),
                ];
            })
            ->filter(fn (array $cluster): bool => $cluster['media_count'] >= 2 && $cluster['account_count'] >= 2)
            ->values()
            ->all();

        usort($clusters, function (array $a, array $b) use ($sort): int {
            if ($sort === 'newest_desc') {
                return [$b['newest_at'] ?? '', $b['media_count'], $b['id']]
                    <=> [$a['newest_at'] ?? '', $a['media_count'], $a['id']];
            }

            return [$b['media_count'], $b['account_count'], $b['newest_at'] ?? '', $b['id']]
                <=> [$a['media_count'], $a['account_count'], $a['newest_at'] ?? '', $a['id']];
        });

        return $clusters;
    }

    /**
     * @return array{truncated: bool, scanned_media_count: int, scan_limit: int}
     */
    public function scanStatus(): array
    {
        $photos = $this->photos();

        return [
            'truncated' => $this->scanTruncated,
            'scanned_media_count' => $photos->count(),
            'scan_limit' => $this->scanLimit(),
        ];
    }

    /**
     * @return Collection<int, Media>
     */
    private function photos(): Collection
    {
        if ($this->photos instanceof Collection) {
            return $this->photos;
        }

        $limit = $this->scanLimit();
        $photos = Media::query()
            ->with([
                'interests',
                'user' => fn ($query) => $query->withTrashed(),
            ])
            ->where('type', MediaType::Photo->value)
            ->where('upload_status', 'ready')
            ->whereNotNull('pdq_hash')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $this->scanTruncated = $photos->count() > $limit;
        $this->photos = $photos->take($limit)->keyBy('id');

        return $this->photos;
    }

    /**
     * @return list<array{left_id: int, right_id: int, distance: int}>
     */
    private function pairs(): array
    {
        if ($this->pairs !== null) {
            return $this->pairs;
        }

        $this->pairs = $this->sharedPairs();

        return $this->pairs;
    }

    /**
     * All drivers deliberately use the same distance implementation. This
     * avoids a silent MySQL/SQLite signedness divergence at 64-bit chunk
     * boundaries and lets the SQLite suite exercise the production algorithm.
     *
     * The pdq_hash index added by
     * 2026_07_29_060000_add_pdq_hash_index_to_media only helps locate non-null
     * candidates; it cannot accelerate a computed Hamming-distance predicate.
     * photos() bounds that otherwise-quadratic candidate set before this loop.
     *
     * @return list<array{left_id: int, right_id: int, distance: int}>
     */
    private function sharedPairs(): array
    {
        $photos = $this->photos()->values();
        $pairs = [];
        $count = $photos->count();
        $threshold = $this->threshold();

        for ($leftIndex = 0; $leftIndex < $count; $leftIndex++) {
            $left = $photos->get($leftIndex);
            if (! $left instanceof Media) {
                continue;
            }
            for ($rightIndex = $leftIndex + 1; $rightIndex < $count; $rightIndex++) {
                $right = $photos->get($rightIndex);
                if (! $right instanceof Media || $left->user_id === $right->user_id) {
                    continue;
                }

                $distance = PerceptualHash::hammingDistanceHex($left->pdq_hash, $right->pdq_hash);
                if ($distance !== null && $distance <= $threshold) {
                    $pairs[] = [
                        'left_id' => $left->id,
                        'right_id' => $right->id,
                        'distance' => $distance,
                    ];
                }
            }
        }

        return $pairs;
    }

    /**
     * @return array<string, mixed>
     */
    private function matchPayload(Media $media, int $distance): array
    {
        return [
            'media_id' => $media->id,
            'media_href' => route('media.view', ['ulid' => $media->ulid], false),
            'account_id' => $media->user_id,
            'account_name' => $media->user?->display_name ?: $media->user?->name,
            'account_email' => $media->user?->email,
            'account_href' => route('admin.users', [], false).'#user-'.$media->user_id,
            'distance' => $distance,
        ];
    }

    private function threshold(): int
    {
        return max(0, min(256, (int) config('media.pdq_global_threshold', 15)));
    }

    private function scanLimit(): int
    {
        return max(2, min(1000, (int) config('media.pdq_global_scan_limit', 500)));
    }

    /**
     * @param  array<int, int>  $parents
     */
    private function root(array &$parents, int $id): int
    {
        while ($parents[$id] !== $id) {
            $parents[$id] = $parents[$parents[$id]];
            $id = $parents[$id];
        }

        return $id;
    }

    /**
     * @param  array<int, int>  $parents
     */
    private function union(array &$parents, int $left, int $right): void
    {
        $leftRoot = $this->root($parents, $left);
        $rightRoot = $this->root($parents, $right);
        if ($leftRoot !== $rightRoot) {
            $parents[$rightRoot] = $leftRoot;
        }
    }
}
