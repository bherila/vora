<?php

namespace App\Services\Profile;

use App\Models\Character;
use App\Models\Favorite;
use App\Models\InterestRating;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use App\Support\CharacterPresenter;

/**
 * Builds the visitor-facing persona header shared by /c/{ulid} and /me's
 * owner-only View as preview.
 */
class PersonaProfilePayload
{
    public function __construct(private readonly MediaResponseService $responder) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Character $character, User $viewer, bool $allowMutations = true): array
    {
        $owner = $character->user;
        $isOwner = $viewer->id === $character->user_id;

        return CharacterPresenter::publicCard($character, $this->responder, $viewer) + [
            'is_owner' => $isOwner,
            'is_linked' => $character->is_linked,
            'owner' => $character->is_linked ? [
                'display_name' => $owner->display_name ?: $owner->name,
                'href' => $isOwner ? route('me', [], false) : route('users.profile', $owner, false),
            ] : null,
            'interests' => $this->interests($character),
            'viewer_favorited' => $allowMutations && ! $isOwner && Favorite::query()
                ->where('user_id', $viewer->id)
                ->where('favoritable_type', $character->getMorphClass())
                ->where('favoritable_id', $character->id)
                ->exists(),
            'can_report' => $allowMutations && ! $isOwner,
        ];
    }

    /**
     * @return list<array{id: int, name: string|null}>
     */
    private function interests(Character $character): array
    {
        if ($character->inherit_interests && ! $character->is_linked) {
            return [];
        }

        return InterestRating::query()
            ->with('interest:id,name')
            ->where('user_id', $character->user_id)
            ->where('level', '>', 0)
            ->when(
                $character->inherit_interests,
                fn ($query) => $query->whereNull('character_id'),
                fn ($query) => $query->where('character_id', $character->id),
            )
            ->get()
            ->map(fn (InterestRating $rating): array => [
                'id' => (int) $rating->interest_id,
                'name' => $rating->interest?->name,
            ])
            ->values()
            ->all();
    }
}
