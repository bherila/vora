<?php

namespace App\Support;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\Media;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use Illuminate\Support\Collection;

/**
 * Serializes a Character for owner management (the editor dialog on the profile
 * and the legacy characters API). Includes the editable fields and the profile
 * picture; not used for public/visitor views, which only need the identity strip.
 */
class CharacterPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function manage(Character $character, MediaResponseService $responder): array
    {
        $picture = $character->profilePicture;

        return [
            'id' => $character->id,
            'ulid' => $character->ulid,
            'display_name' => $character->display_name,
            'description' => $character->description,
            'is_linked' => $character->is_linked,
            'audience' => $character->audience->value,
            'audience_user_ids' => $character->audience === Audience::SpecificPeople
                ? $character->audienceMembers()->pluck('user_id')->map('intval')->sort()->values()->all()
                : [],
            'discoverable' => $character->discoverable,
            'gender' => $character->gender,
            'gender_other' => $character->gender_other,
            'user_type' => $character->user_type,
            'user_type_other' => $character->user_type_other,
            'inherit_interests' => $character->inherit_interests,
            'profile_picture' => $picture instanceof Media ? $responder->item($picture, resolveHls: false) : null,
        ];
    }

    /**
     * Compact public persona card for cross-user surfaces: Explore's Personas
     * tab, the People directory, and the persona page header. Deliberately
     * carries nothing that resolves to the human behind the persona — whether
     * the owner is shown is a per-surface decision gated on `is_linked`, made
     * by the caller, never baked into the card.
     *
     * @return array<string, mixed>
     */
    public static function publicCard(Character $character, MediaResponseService $responder, ?User $viewer = null): array
    {
        return [
            'id' => $character->id,
            'ulid' => $character->ulid,
            'display_name' => $character->display_name,
            'description' => $character->description,
            'avatar_url' => UserPresenter::pictureUrl($character->profilePicture, $responder, $viewer),
            'user_type' => $character->user_type,
            'gender' => $character->gender,
            'href' => route('characters.view', ['ulid' => $character->ulid], false),
        ];
    }

    /**
     * @param  Collection<int, Character>  $characters
     * @return list<array<string, mixed>>
     */
    public static function list(Collection $characters, MediaResponseService $responder): array
    {
        return $characters->map(fn (Character $character): array => self::manage($character, $responder))->values()->all();
    }
}
