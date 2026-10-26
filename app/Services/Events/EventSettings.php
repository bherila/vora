<?php

namespace App\Services\Events;

use App\Enums\MediaPurpose;
use App\Enums\ModerationStatus;
use App\Models\Media;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\BlockGraph;
use App\Support\InstanceFeatures;
use Illuminate\Support\Collection;

class EventSettings
{
    public const FEATURED_CURATOR_IDS = 'event_featured_curator_ids';

    public function __construct(private readonly SettingsService $settings) {}

    /** @return list<int> */
    public function featuredCuratorIds(): array
    {
        $value = json_decode($this->settings->get(self::FEATURED_CURATOR_IDS, '[]') ?? '[]', true);

        return is_array($value) ? array_values(array_unique(array_filter($value, fn (mixed $id): bool => is_int($id) && $id > 0))) : [];
    }

    /** @param list<int> $ids */
    public function setFeaturedCuratorIds(array $ids): void
    {
        $this->settings->set(self::FEATURED_CURATOR_IDS, json_encode(array_values(array_unique($ids)), JSON_THROW_ON_ERROR));
    }

    /** @return Collection<int, User> */
    public function featuredCurators(?User $viewer = null): Collection
    {
        if (! InstanceFeatures::enabled('favorites')) {
            return collect();
        }
        $ids = $this->featuredCuratorIds();
        $users = User::query()->active()->whereNotNull('approved_at')->whereIn('id', $ids)->get(['id', 'display_name'])->keyBy('id');

        return collect($ids)->map(fn (int $id): ?User => $users->get($id))->filter()
            ->filter(fn (User $user): bool => $viewer === null || $viewer->isAdmin() || BlockGraph::canViewIdentity($viewer, $user->id))->values();
    }

    /** @return array<string, mixed> */
    public function payload(User $viewer): array
    {
        return [
            'featured_curators' => $this->featuredCurators($viewer)->map(fn (User $user): array => ['id' => $user->id, 'display_name' => $user->display_name])->all(),
            'is_admin' => $viewer->isAdmin(), 'viewer_id' => $viewer->id,
            'uploads_require_review' => ! InstanceFeatures::enabled('events') || ! $viewer->event_uploads_auto_approved,
            // Organizers see how many finished uploads wait for review.
            'pending_review_count' => $viewer->isAdmin() ? Media::query()->where('purpose', MediaPurpose::Event->value)
                ->where('upload_status', 'ready')->where('moderation_status', ModerationStatus::Pending->value)->count() : null,
        ];
    }
}
