<?php

namespace App\Services\Profile;

use App\Models\Character;
use App\Models\RecentProfileVisit;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use App\Services\Privacy\ProfileGate;
use App\Support\CharacterPresenter;
use App\Support\UserPresenter;

class RecentProfileTrail
{
    public const MAX_ENTRIES = 10;

    public const RETENTION_DAYS = 30;

    public function __construct(
        private readonly ProfileGate $profileGate,
        private readonly MediaResponseService $mediaResponder,
    ) {}

    public function recordUser(User $viewer, User $target): void
    {
        if (! $this->mayRecord($viewer) || $viewer->is($target) || ! $this->profileGate->canView($viewer, $target)) {
            return;
        }

        $this->record($viewer, RecentProfileVisit::TARGET_USER, $target->id);
    }

    public function recordCharacter(User $viewer, Character $target): void
    {
        if (! $this->mayRecord($viewer) || $target->user_id === $viewer->id || ! $target->isViewableBy($viewer)) {
            return;
        }

        $this->record($viewer, RecentProfileVisit::TARGET_CHARACTER, $target->id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cards(User $viewer): array
    {
        $visits = RecentProfileVisit::query()
            ->where('viewer_user_id', $viewer->id)
            ->where('visited_at', '>=', now()->subDays(self::RETENTION_DAYS))
            ->latest('visited_at')
            ->latest('id')
            ->limit(self::MAX_ENTRIES)
            ->get();

        $cards = $visits->map(function (RecentProfileVisit $visit) use ($viewer): ?array {
            $card = $visit->target_type === RecentProfileVisit::TARGET_USER
                ? $this->userCard($viewer, $visit->target_id)
                : $this->characterCard($viewer, $visit->target_id);

            if ($card === null) {
                $visit->delete();

                return null;
            }

            return $card + ['visited_at' => $visit->visited_at?->toIso8601String()];
        })->filter()->values();

        $this->prune($viewer);

        /** @var list<array<string, mixed>> */
        return $cards->all();
    }

    public function clear(User $viewer): void
    {
        RecentProfileVisit::query()->where('viewer_user_id', $viewer->id)->delete();
    }

    private function mayRecord(User $viewer): bool
    {
        return $viewer->exists && $viewer->id > 0 && ! $viewer->isAdmin();
    }

    private function record(User $viewer, string $targetType, int $targetId): void
    {
        RecentProfileVisit::query()->updateOrCreate(
            [
                'viewer_user_id' => $viewer->id,
                'target_type' => $targetType,
                'target_id' => $targetId,
            ],
            ['visited_at' => now()],
        );

        $this->prune($viewer);
    }

    private function prune(User $viewer): void
    {
        RecentProfileVisit::query()
            ->where('viewer_user_id', $viewer->id)
            ->where('visited_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->delete();

        $keepIds = RecentProfileVisit::query()
            ->where('viewer_user_id', $viewer->id)
            ->latest('visited_at')
            ->latest('id')
            ->limit(self::MAX_ENTRIES)
            ->pluck('id');

        RecentProfileVisit::query()
            ->where('viewer_user_id', $viewer->id)
            ->when($keepIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $keepIds))
            ->delete();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function userCard(User $viewer, int $targetId): ?array
    {
        $target = User::query()->active()->whereNotNull('approved_at')->with('profilePicture')->find($targetId);
        if (! $target instanceof User || ! $this->profileGate->canView($viewer, $target)) {
            return null;
        }

        return [
            'type' => RecentProfileVisit::TARGET_USER,
            'id' => $target->id,
            'display_name' => $target->display_name ?: $target->name,
            'avatar_url' => UserPresenter::avatarUrl($target, $this->mediaResponder, $viewer),
            'href' => route('users.profile', ['user' => $target->id], false),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function characterCard(User $viewer, int $targetId): ?array
    {
        $target = Character::query()
            ->with(['user', 'profilePicture'])
            ->whereHas('user', fn ($query) => $query->active()->whereNotNull('approved_at'))
            ->find($targetId);
        if (! $target instanceof Character || ! $target->isViewableBy($viewer)) {
            return null;
        }

        return ['type' => RecentProfileVisit::TARGET_CHARACTER]
            + CharacterPresenter::publicCard($target, $this->mediaResponder, $viewer);
    }
}
