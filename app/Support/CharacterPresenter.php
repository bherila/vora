<?php

namespace App\Support;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\Media;
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
            'display_name' => $character->display_name,
            'description' => $character->description,
            'audience' => $character->audience->value,
            'audience_user_ids' => $character->audience === Audience::SpecificPeople
                ? $character->audienceMembers()->pluck('user_id')->map('intval')->sort()->values()->all()
                : [],
            'gender' => $character->gender,
            'gender_other' => $character->gender_other,
            'user_type' => $character->user_type,
            'user_type_other' => $character->user_type_other,
            'preferred_user_types' => $character->preferred_user_types ?? [],
            'preferred_genders' => $character->preferred_genders ?? [],
            'inherit_interests' => $character->inherit_interests,
            'profile_picture' => $picture instanceof Media ? $responder->item($picture, resolveHls: false) : null,
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
